<?php

namespace App\Listeners;

use App\Enums\InternshipDocumentStatus;
use App\Enums\NotificationType;
use App\Events\InternshipDocumentStatusChanged;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies the coordinating faculty member when a document is
 * submitted for review (toStatus === SUBMITTED - covers both a fresh
 * upload, fromStatus null, and a resubmission, fromStatus
 * CHANGES_REQUESTED, since App\Services\InternshipDocumentService's
 * upload()/resubmit() both land on SUBMITTED), and notifies the
 * uploading student when it is reviewed (toStatus === APPROVED or
 * CHANGES_REQUESTED) - the module spec's "Faculty: document submitted"
 * / "Student: document reviewed". The UNDER_REVIEW transition
 * (App\Services\InternshipDocumentService::startReview()) is not
 * itself "submitted" or "reviewed" and is not notified. Registered
 * against App\Events\InternshipDocumentStatusChanged in
 * App\Providers\AppServiceProvider::boot(). See
 * App\Listeners\SendApplicationStatusNotification's docblock for why
 * every listener in this module wraps its body in try/catch(Throwable).
 */
class SendInternshipDocumentNotification
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function handle(InternshipDocumentStatusChanged $event): void
    {
        try {
            $document = $event->document;
            $assignment = $document->internshipAssignment;

            if ($event->toStatus === InternshipDocumentStatus::SUBMITTED) {
                $recipient = $assignment?->faculty?->user;

                if ($recipient === null) {
                    return;
                }

                $this->notificationService->create(
                    $recipient,
                    NotificationType::DOCUMENT_SUBMITTED,
                    'Document submitted',
                    sprintf('A document ("%s") was submitted for your review.', $document->title),
                    ['internship_document_id' => $document->id],
                );

                return;
            }

            if (in_array($event->toStatus, [InternshipDocumentStatus::APPROVED, InternshipDocumentStatus::CHANGES_REQUESTED], true)) {
                $recipient = $assignment?->student?->user;

                if ($recipient === null) {
                    return;
                }

                $this->notificationService->create(
                    $recipient,
                    NotificationType::DOCUMENT_REVIEWED,
                    'Document reviewed',
                    sprintf('Your document ("%s") was %s.', $document->title, strtolower(str_replace('_', ' ', $event->toStatus->value))),
                    ['internship_document_id' => $document->id, 'status' => $event->toStatus->value],
                );
            }
        } catch (Throwable $e) {
            Log::warning('Failed to create internship document notification.', [
                'exception' => $e->getMessage(),
                'internship_document_id' => $event->document->id ?? null,
            ]);
        }
    }
}
