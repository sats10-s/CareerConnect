<?php

namespace App\Services;

use App\Enums\InternshipAssignmentStatus;
use App\Enums\InternshipCompletionStatus;
use App\Enums\PpoStatus;
use App\Events\InternshipCompletionStatusChanged;
use App\Events\PpoRecordStatusChanged;
use App\Exceptions\InternshipCompletionException;
use App\Exceptions\PpoRecordException;
use App\Models\InternshipAssignment;
use App\Models\InternshipCompletion;
use App\Models\InternshipCompletionStatusHistory;
use App\Models\InternshipDocument;
use App\Models\PpoRecord;
use App\Models\PpoRecordStatusHistory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single place every internship-completion AND PPO write goes
 * through - creating/updating/finalizing a completion record, and
 * creating/updating a PPO record. Both resources are covered by one
 * service class (matching the module spec's literal "InternshipCompletionService"
 * naming, which groups completion and PPO together as one module,
 * "Internship Completion & PPO") but are otherwise fully independent:
 * neither table references the other, and nothing about a PPO record
 * requires a completion record to exist first, or vice versa. Callers
 * (Api\Faculty\InternshipCompletionController, Api\Faculty\PpoRecordController)
 * never touch `internship_completions`/`internship_completion_status_history`/
 * `ppo_records`/`ppo_record_status_history` directly - mirrors
 * App\Services\InternshipDocumentService's own "single writer" design
 * exactly.
 *
 * OWNERSHIP - every mutating method takes an already-resolved
 * InternshipAssignment/InternshipCompletion/PpoRecord model, never a
 * raw client-supplied id for student/faculty/company: `completed_by`
 * is always the acting User's own id, and `company_id` on a new PPO
 * record is always DERIVED from `$assignment->internship->company_id`,
 * never accepted as a request field (see StorePpoRecordRequest, which
 * has no company_id rule at all). Object-level authorization (which
 * assignment/completion/PPO a caller may act on) is enforced entirely
 * by App\Policies\InternshipCompletionPolicy/PpoRecordPolicy, one
 * layer up, before any of these methods are ever called.
 *
 * ASSIGNMENT-LIFECYCLE GATING - unlike InternshipDocumentService
 * (which deliberately never checks assignment status), every mutating
 * method here DOES gate on the assignment currently being ACTIVE or
 * COMPLETED (see assertAssignmentEligible()) - per the module spec's
 * explicit "Only appropriate ACTIVE/COMPLETED assignment states may be
 * completed." An ASSIGNED assignment has not started yet (nothing to
 * assess), and a CANCELLED one never will (see
 * InternshipAssignmentStatus::canTransitionTo() - CANCELLED is
 * terminal), so neither is eligible for a completion or PPO record.
 * The same gate is applied to PPO by extension/symmetry - the spec
 * does not separately restate it for PPO, but a pre-placement offer
 * tied to an assignment that was never active or never finished makes
 * no more sense than a completion record for one, and this keeps a
 * single, consistently-enforced rule rather than two silently
 * different ones.
 *
 * IMMUTABILITY - a FINALIZED completion (InternshipCompletionStatus::isTerminal())
 * and a terminal PPO record (PpoStatus::isTerminal() - ACCEPTED or
 * DECLINED) can never be updated again through update()/updatePpo();
 * this is re-checked here as the load-bearing rule (not just in the
 * policy layer - see InternshipCompletionPolicy::update()/
 * PpoRecordPolicy::update()'s own docblocks for why both layers check
 * it).
 *
 * NEVER AUTOMATICALLY ALTERS THE ASSIGNMENT OR APPLICATION - per the
 * module spec's "Do not automatically alter application/assignment
 * status unless existing lifecycle requires it," none of these methods
 * ever writes to internship_assignments.status or applications.status.
 * Marking an assignment COMPLETED remains exclusively
 * App\Services\InternshipAssignmentService::complete()'s job (an
 * admin-only action, unrelated to this module) - a completion record
 * here is a separate, richer assessment that can be created either
 * while the assignment is still ACTIVE or after it has already been
 * marked COMPLETED by that pre-existing action.
 */
class InternshipCompletionService
{
    // --- Internship Completion ---------------------------------------------

    /**
     * @param array{completion_date: string, outcome: string, final_remarks?: string|null, completion_certificate_document_id?: int|null} $data
     */
    public function createCompletion(InternshipAssignment $assignment, User $actingUser, array $data): InternshipCompletion
    {
        $this->assertAssignmentEligible($assignment, InternshipCompletionException::class);

        if (InternshipCompletion::where('internship_assignment_id', $assignment->id)->exists()) {
            throw new InternshipCompletionException('A completion record already exists for this assignment. Use PATCH to update it.');
        }

        $this->assertCertificateDocumentBelongsToAssignment($data['completion_certificate_document_id'] ?? null, $assignment);

        try {
            return DB::transaction(function () use ($assignment, $actingUser, $data) {
                $completion = InternshipCompletion::create([
                    'internship_assignment_id' => $assignment->id,
                    'completion_date' => $data['completion_date'],
                    'outcome' => $data['outcome'],
                    'final_remarks' => $data['final_remarks'] ?? null,
                    'completion_certificate_document_id' => $data['completion_certificate_document_id'] ?? null,
                    'completed_by' => $actingUser->id,
                    'status' => InternshipCompletionStatus::DRAFT,
                ]);

                $this->recordCompletionHistory($completion, null, InternshipCompletionStatus::DRAFT, $actingUser, null);
                event(new InternshipCompletionStatusChanged($completion, null, InternshipCompletionStatus::DRAFT, $actingUser));

                return $completion;
            });
        } catch (QueryException $e) {
            // Safety net for a race between the exists() check above and
            // this insert - the DB-level unique constraint on
            // internship_completions.internship_assignment_id is the
            // actual source of truth; this just turns its violation
            // into the same clean business error rather than a raw 500.
            // Same pattern as InternshipAssignmentService::create().
            if ($e->getCode() === '23000') {
                throw new InternshipCompletionException('A completion record already exists for this assignment.');
            }

            throw $e;
        }
    }

    /**
     * @param array{completion_date?: string, outcome?: string, final_remarks?: string|null, completion_certificate_document_id?: int|null} $data
     */
    public function updateCompletion(InternshipCompletion $completion, User $actingUser, array $data): InternshipCompletion
    {
        if ($completion->status->isTerminal()) {
            throw new InternshipCompletionException('This completion record has been finalized and can no longer be changed.');
        }

        $this->assertAssignmentEligible($completion->internshipAssignment, InternshipCompletionException::class);

        if (array_key_exists('completion_certificate_document_id', $data)) {
            $this->assertCertificateDocumentBelongsToAssignment($data['completion_certificate_document_id'], $completion->internshipAssignment);
        }

        $updates = array_intersect_key($data, array_flip([
            'completion_date', 'outcome', 'final_remarks', 'completion_certificate_document_id',
        ]));

        if ($updates !== []) {
            $completion->update($updates);
        }

        return $completion->fresh();
    }

    /**
     * DRAFT -> FINALIZED. Terminal - see
     * InternshipCompletionStatus::isTerminal(). $finalRemarks, if
     * given, replaces final_remarks as part of the same action (a
     * faculty member confirming completion often wants to add one last
     * note at the moment of finalizing, mirroring
     * CompleteInternshipAssignmentRequest's own optional `remarks`
     * precedent) - a null/omitted value leaves the existing
     * final_remarks untouched.
     */
    public function finalizeCompletion(InternshipCompletion $completion, User $actingUser, ?string $finalRemarks = null): InternshipCompletion
    {
        if (! $completion->status->canTransitionTo(InternshipCompletionStatus::FINALIZED)) {
            throw new InternshipCompletionException('This completion record has already been finalized.');
        }

        $this->assertAssignmentEligible($completion->internshipAssignment, InternshipCompletionException::class);

        return DB::transaction(function () use ($completion, $actingUser, $finalRemarks) {
            $from = $completion->status;

            $updates = [
                'status' => InternshipCompletionStatus::FINALIZED,
                'finalized_at' => now(),
            ];

            if ($finalRemarks !== null) {
                $updates['final_remarks'] = $finalRemarks;
            }

            $completion->update($updates);

            $this->recordCompletionHistory($completion, $from, InternshipCompletionStatus::FINALIZED, $actingUser, $finalRemarks);
            event(new InternshipCompletionStatusChanged($completion, $from, InternshipCompletionStatus::FINALIZED, $actingUser));

            return $completion->fresh();
        });
    }

    // --- PPO -----------------------------------------------------------------

    /**
     * `company_id` is always derived from the assignment's own
     * internship - see this class's own docblock; there is no
     * `company_id` key accepted in $data at all.
     *
     * @param array{offered_at: string, joining_date?: string|null, designation: string, package?: string|null, remarks?: string|null} $data
     */
    public function createPpo(InternshipAssignment $assignment, User $actingUser, array $data): PpoRecord
    {
        $this->assertAssignmentEligible($assignment, PpoRecordException::class);

        if (PpoRecord::where('internship_assignment_id', $assignment->id)->exists()) {
            throw new PpoRecordException('A PPO record already exists for this assignment. Use PATCH to update it.');
        }

        $companyId = $assignment->internship?->company_id;

        if ($companyId === null) {
            throw new PpoRecordException('This assignment has no associated company to offer a PPO from.', status: 500);
        }

        try {
            return DB::transaction(function () use ($assignment, $companyId, $actingUser, $data) {
                $ppoRecord = PpoRecord::create([
                    'internship_assignment_id' => $assignment->id,
                    'company_id' => $companyId,
                    'offered_at' => $data['offered_at'],
                    'joining_date' => $data['joining_date'] ?? null,
                    'designation' => $data['designation'],
                    'package' => $data['package'] ?? null,
                    'status' => PpoStatus::OFFERED,
                    'remarks' => $data['remarks'] ?? null,
                ]);

                $this->recordPpoHistory($ppoRecord, null, PpoStatus::OFFERED, $actingUser, null);
                event(new PpoRecordStatusChanged($ppoRecord, null, PpoStatus::OFFERED, $actingUser));

                return $ppoRecord;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw new PpoRecordException('A PPO record already exists for this assignment.');
            }

            throw $e;
        }
    }

    /**
     * Updates a PPO record's own fields and/or transitions its status
     * (OFFERED -> ACCEPTED/DECLINED). `status`, when present in $data,
     * is re-validated against PpoStatus::canTransitionTo() - never
     * trusted as a bare assignment. Once the record reaches a terminal
     * status it can never be updated again (see this class's own
     * docblock).
     *
     * @param array{offered_at?: string, joining_date?: string|null, designation?: string, package?: string|null, remarks?: string|null, status?: string} $data
     */
    public function updatePpo(PpoRecord $ppoRecord, User $actingUser, array $data): PpoRecord
    {
        if ($ppoRecord->status->isTerminal()) {
            throw new PpoRecordException('This PPO record has already been decided and can no longer be changed.');
        }

        $this->assertAssignmentEligible($ppoRecord->internshipAssignment, PpoRecordException::class);

        $fieldUpdates = array_intersect_key($data, array_flip([
            'offered_at', 'joining_date', 'designation', 'package', 'remarks',
        ]));

        return DB::transaction(function () use ($ppoRecord, $actingUser, $data, $fieldUpdates) {
            if ($fieldUpdates !== []) {
                $ppoRecord->update($fieldUpdates);
            }

            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $target = PpoStatus::from($data['status']);

                if (! $ppoRecord->status->canTransitionTo($target)) {
                    throw new PpoRecordException(sprintf(
                        'This PPO record cannot move from %s to %s.',
                        $ppoRecord->status->value,
                        $target->value,
                    ));
                }

                $from = $ppoRecord->status;
                $ppoRecord->update(['status' => $target]);

                $this->recordPpoHistory($ppoRecord, $from, $target, $actingUser, $data['remarks'] ?? null);
                event(new PpoRecordStatusChanged($ppoRecord, $from, $target, $actingUser));
            }

            return $ppoRecord->fresh();
        });
    }

    // --- shared helpers --------------------------------------------------------

    /**
     * Only an ACTIVE or COMPLETED assignment is eligible for a
     * completion/PPO write - see this class's own docblock.
     *
     * $exceptionClass lets the SAME check be reused by both the
     * completion methods (which must throw InternshipCompletionException -
     * the only type Api\Faculty\InternshipCompletionController catches)
     * and the PPO methods (which must throw PpoRecordException - the
     * only type Api\Faculty\PpoRecordController catches). Throwing the
     * wrong type here would bubble up as an uncaught exception (a raw
     * 500) instead of the clean 422 business error every other failure
     * path in this service returns.
     *
     * @param class-string<InternshipCompletionException|PpoRecordException> $exceptionClass
     */
    private function assertAssignmentEligible(InternshipAssignment $assignment, string $exceptionClass): void
    {
        if (! in_array($assignment->status, [InternshipAssignmentStatus::ACTIVE, InternshipAssignmentStatus::COMPLETED], true)) {
            throw new $exceptionClass(sprintf(
                'A completion or PPO record can only be created or updated for an active or completed internship assignment (this assignment is %s).',
                $assignment->status->value,
            ));
        }
    }

    /**
     * A completion_certificate_document_id, if supplied, must point at
     * a real InternshipDocument that belongs to THIS SAME assignment -
     * never trusted as a bare id (per this module's IDOR-prevention
     * requirement, extended to this cross-reference too: a client could
     * otherwise link a completion record to a completely unrelated
     * student's document).
     */
    private function assertCertificateDocumentBelongsToAssignment(?int $documentId, InternshipAssignment $assignment): void
    {
        if ($documentId === null) {
            return;
        }

        $belongs = InternshipDocument::where('id', $documentId)
            ->where('internship_assignment_id', $assignment->id)
            ->exists();

        if (! $belongs) {
            throw new InternshipCompletionException('The certificate document must belong to this same internship assignment.');
        }
    }

    private function recordCompletionHistory(InternshipCompletion $completion, ?InternshipCompletionStatus $from, InternshipCompletionStatus $to, User $actingUser, ?string $remarks): void
    {
        InternshipCompletionStatusHistory::create([
            'internship_completion_id' => $completion->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }

    private function recordPpoHistory(PpoRecord $ppoRecord, ?PpoStatus $from, PpoStatus $to, User $actingUser, ?string $remarks): void
    {
        PpoRecordStatusHistory::create([
            'ppo_record_id' => $ppoRecord->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actingUser->id,
            'remarks' => $remarks,
        ]);
    }
}
