<?php

namespace App\Services;

use App\Enums\InternshipDocumentStatus;
use App\Events\InternshipDocumentStatusChanged;
use App\Exceptions\InternshipDocumentException;
use App\Models\InternshipAssignment;
use App\Models\InternshipDocument;
use App\Models\InternshipDocumentStatusHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The single place every internship-document write goes through:
 * uploading a new document, resubmitting one after CHANGES_REQUESTED,
 * and every faculty/admin review action (start review / approve /
 * request changes) - plus the one sanctioned path to actually read a
 * stored file's bytes (retrieveForDownload()). Callers
 * (Api\Student\InternshipDocumentController,
 * Api\Faculty\InternshipDocumentController,
 * Api\Admin\InternshipDocumentController) never touch
 * `internship_documents`/`internship_document_status_history` directly
 * and never call Storage themselves - mirrors
 * App\Services\WeeklyProgressReportService's own "single writer"
 * design exactly.
 *
 * STORAGE SECURITY (module spec section 4): every file lives on the
 * `local` filesystem disk (storage/app/private/..., see
 * config/filesystems.php - no symlink into public/, no public URL).
 * The on-disk filename is always a random 40-character string with an
 * extension derived from the file's own detected content type
 * (UploadedFile::extension(), never the client-supplied original
 * filename) under `documents/{assignment_id}/...` - nothing about the
 * stored path is derived from client input. `original_filename` is
 * kept purely as a display value and is never used to build a
 * filesystem path. This mirrors
 * App\Http\Controllers\Api\Student\ResumeController::store() exactly,
 * the pre-existing precedent for private file storage in this
 * codebase.
 *
 * FILE REPLACEMENT ORDERING (module spec section 5): resubmit() always
 * stores the NEW file first, confirms the DB update inside a
 * transaction, and only deletes the OLD file after that transaction
 * commits. If storing the new file fails, nothing is touched. If the
 * DB update fails after the new file was stored, the newly-stored file
 * is deleted (never left orphaned) and the old file/DB row are left
 * completely untouched. The old file is never deleted first.
 *
 * ASSIGNMENT-LIFECYCLE GATING - deliberately NOT enforced here, unlike
 * AttendanceService/WeeklyProgressReportService (which both block
 * writes once the assignment is COMPLETED/CANCELLED). The module spec
 * never asks for this, and several document types
 * (COMPLETION_CERTIFICATE, FINAL_REPORT) are only ever produced AFTER
 * an internship assignment has been marked COMPLETED - gating document
 * upload/review by assignment status would make it impossible to ever
 * record the very documents that close out an internship. Ownership
 * (which assignment a document belongs to, and who may act on it) is
 * still fully enforced via App\Policies\InternshipDocumentPolicy.
 */
class InternshipDocumentService
{
    private const DISK = 'local';

    /**
     * @param array{document_type: string, title: string, student_notes?: string|null} $data
     */
    public function upload(InternshipAssignment $assignment, User $actingUser, UploadedFile $file, array $data): InternshipDocument
    {
        $stored = $this->storeFile($file, $assignment);

        try {
            return DB::transaction(function () use ($assignment, $actingUser, $stored, $data, $file) {
                $document = InternshipDocument::create([
                    'internship_assignment_id' => $assignment->id,
                    'uploaded_by' => $actingUser->id,
                    'document_type' => $data['document_type'],
                    'title' => $data['title'],
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_filename' => $stored['stored_filename'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                    'status' => InternshipDocumentStatus::SUBMITTED,
                    'student_notes' => $data['student_notes'] ?? null,
                    'submitted_at' => now(),
                ]);

                $this->recordHistory($document, null, InternshipDocumentStatus::SUBMITTED, $actingUser, null);
                event(new InternshipDocumentStatusChanged($document, null, InternshipDocumentStatus::SUBMITTED, $actingUser));

                return $document;
            });
        } catch (Throwable $e) {
            // The DB write failed - remove the file we just stored so
            // it isn't left behind as an orphan (mirrors
            // ResumeController::store()'s own orphan-prevention).
            Storage::disk(self::DISK)->delete($stored['storage_path']);
            throw $e;
        }
    }

    /**
     * Only allowed while status.isResubmittable() (CHANGES_REQUESTED).
     * See this class's own docblock for the exact store-then-commit-
     * then-delete-old ordering this method follows.
     *
     * @param array{student_notes?: string|null} $data
     */
    public function resubmit(InternshipDocument $document, User $actingUser, UploadedFile $file, array $data): InternshipDocument
    {
        if (! $document->status->isResubmittable()) {
            throw new InternshipDocumentException(sprintf(
                'This document cannot be resubmitted in its current status (%s).',
                $document->status->value,
            ));
        }

        $previousPath = $document->storage_path;
        $stored = $this->storeFile($file, $document->internshipAssignment);

        try {
            $document = DB::transaction(function () use ($document, $actingUser, $stored, $data, $file) {
                $from = $document->status;

                $document->update([
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_filename' => $stored['stored_filename'],
                    'storage_path' => $stored['storage_path'],
                    'mime_type' => $stored['mime_type'],
                    'file_size' => $stored['file_size'],
                    'status' => InternshipDocumentStatus::SUBMITTED,
                    'student_notes' => array_key_exists('student_notes', $data) ? $data['student_notes'] : $document->student_notes,
                    'submitted_at' => now(),
                ]);

                $this->recordHistory($document, $from, InternshipDocumentStatus::SUBMITTED, $actingUser, null);
                event(new InternshipDocumentStatusChanged($document, $from, InternshipDocumentStatus::SUBMITTED, $actingUser));

                return $document->fresh();
            });
        } catch (Throwable $e) {
            // The DB update failed AFTER the new file was already
            // stored - delete the newly-stored file (never orphan it)
            // and leave the OLD file/DB row completely untouched, so
            // the document is left exactly as it was before this
            // attempt (per the module spec's "retain the old document/
            // file" / "retain existing DB state" requirement).
            Storage::disk(self::DISK)->delete($stored['storage_path']);
            throw $e;
        }

        // Only now - after the new file is confirmed stored AND the DB
        // row successfully points at it - remove the previous one.
        // Never delete the old file first.
        if ($previousPath && $previousPath !== $document->storage_path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return $document;
    }

    /**
     * SUBMITTED -> UNDER_REVIEW. No remarks are expected for this
     * action - see transition()'s docblock for why any pre-existing
     * faculty_review_remarks is left untouched here.
     */
    public function startReview(InternshipDocument $document, User $actingUser): InternshipDocument
    {
        return $this->transition($document, InternshipDocumentStatus::UNDER_REVIEW, $actingUser, null);
    }

    /**
     * UNDER_REVIEW -> APPROVED (terminal - see
     * InternshipDocumentStatus::isTerminal()). $remarks is optional
     * here, unlike requestChanges().
     */
    public function approve(InternshipDocument $document, User $actingUser, ?string $remarks): InternshipDocument
    {
        return $this->transition($document, InternshipDocumentStatus::APPROVED, $actingUser, $remarks);
    }

    /**
     * UNDER_REVIEW -> CHANGES_REQUESTED. $remarks is REQUIRED and must
     * be meaningful (non-blank) - see transition()'s own check - per
     * the module spec's "remarks nullable/required where appropriate".
     */
    public function requestChanges(InternshipDocument $document, User $actingUser, string $remarks): InternshipDocument
    {
        return $this->transition($document, InternshipDocumentStatus::CHANGES_REQUESTED, $actingUser, $remarks);
    }

    /**
     * The one sanctioned way to read a stored document's bytes -
     * callers never touch Storage or `storage_path` directly (see this
     * class's own docblock and InternshipDocumentResource's, which
     * never exposes `storage_path`/`stored_filename` at all). Returns
     * a proper controlled 404 (never a raw filesystem exception) when
     * the physical file is missing, per the module spec's "If the file
     * no longer exists: return a proper 404/controlled application
     * error."
     */
    public function retrieveForDownload(InternshipDocument $document): StreamedResponse
    {
        if (! Storage::disk(self::DISK)->exists($document->storage_path)) {
            throw new InternshipDocumentException('The requested file could not be found.', status: 404);
        }

        return Storage::disk(self::DISK)->download($document->storage_path, $document->original_filename);
    }

    /**
     * Shared implementation for every faculty/admin review action -
     * startReview()/approve()/requestChanges(). Never touches
     * student_notes or any of the document's own content fields (title/
     * document_type/original_filename/the stored file itself) - per
     * the module spec's "Faculty cannot directly modify student_notes"
     * / "Faculty cannot replace uploaded files".
     */
    private function transition(InternshipDocument $document, InternshipDocumentStatus $to, User $actingUser, ?string $remarks): InternshipDocument
    {
        if (! $document->status->canTransitionTo($to)) {
            throw new InternshipDocumentException(sprintf(
                'This document cannot move from %s to %s.',
                $document->status->value,
                $to->value,
            ));
        }

        if ($to === InternshipDocumentStatus::CHANGES_REQUESTED && trim((string) $remarks) === '') {
            throw new InternshipDocumentException('Requesting changes requires meaningful review remarks.');
        }

        return DB::transaction(function () use ($document, $to, $actingUser, $remarks) {
            $from = $document->status;

            $updates = [
                'status' => $to,
                'reviewed_at' => now(),
            ];

            // Only overwrite faculty_review_remarks when THIS action
            // actually supplied some - startReview() passes null and
            // must never blank out a prior round's remarks (mirrors
            // WeeklyProgressReportService::transition()'s identical
            // rule).
            if ($remarks !== null) {
                $updates['faculty_review_remarks'] = $remarks;
            }

            if ($to === InternshipDocumentStatus::APPROVED) {
                $updates['approved_at'] = now();
            }

            if ($to === InternshipDocumentStatus::CHANGES_REQUESTED) {
                $updates['changes_requested_at'] = now();
            }

            $document->update($updates);

            $this->recordHistory($document, $from, $to, $actingUser, $remarks);
            event(new InternshipDocumentStatusChanged($document, $from, $to, $actingUser));

            return $document->fresh();
        });
    }

    /**
     * Stores $file on the private `local` disk under a path derived
     * ONLY from the assignment's own id and a random, server-generated
     * filename - never from client input (original filename, claimed
     * MIME type, or any client-supplied path). Throws (rather than
     * returning a falsy value) on a genuine storage failure, mirroring
     * ResumeController::store()'s abort(500, ...) precedent - this is
     * an infrastructure failure, not a validation failure, so a 500 is
     * the correct/expected response for it.
     *
     * @return array{stored_filename: string, storage_path: string, mime_type: string, file_size: int}
     */
    private function storeFile(UploadedFile $file, InternshipAssignment $assignment): array
    {
        $storedFilename = Str::random(40).'.'.$file->extension();
        $storagePath = $file->storeAs('documents/'.$assignment->id, $storedFilename, self::DISK);

        if ($storagePath === false) {
            throw new InternshipDocumentException('Could not store the document. Please try again.', status: 500);
        }

        return [
            'stored_filename' => $storedFilename,
            'storage_path' => $storagePath,
            'mime_type' => $file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream',
            'file_size' => $file->getSize() ?? 0,
        ];
    }

    private function recordHistory(InternshipDocument $document, ?InternshipDocumentStatus $from, InternshipDocumentStatus $to, User $actingUser, ?string $remarks): void
    {
        InternshipDocumentStatusHistory::create([
            'internship_document_id' => $document->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }
}
