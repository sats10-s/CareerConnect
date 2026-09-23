<?php

namespace App\Http\Requests\Concerns;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Throwable;

/**
 * Shared by StorePlacementDriveRequest/UpdatePlacementDriveRequest:
 * "the application deadline must not be after the scheduled drive
 * date, when both are supplied". This only checks the two fields as
 * THIS request submitted them - on a partial PATCH that only sends
 * one of the two, it has no way to see the drive's other, already-
 * stored value, so it can't catch every case by itself. The
 * authoritative check is App\Services\PlacementDriveService::
 * assertDeadlineNotAfterSchedule(), which is always run again against
 * the MERGED (existing + incoming) values before anything is
 * persisted; this trait is just a fast-fail convenience for the
 * common case where an admin submits both fields together.
 */
trait ValidatesDriveScheduling
{
    protected function applyDriveSchedulingRule(ValidatorContract $validator): void
    {
        $validator->after(function ($validator) {
            $scheduledAt = $this->input('scheduled_at');
            $deadline = $this->input('application_deadline');

            if (! $scheduledAt || ! $deadline) {
                return;
            }

            try {
                $scheduledAtParsed = Carbon::parse($scheduledAt);
                $deadlineParsed = Carbon::parse($deadline);
            } catch (Throwable) {
                // Malformed dates are already caught by the `date` rule
                // on each field individually - nothing more to add here.
                return;
            }

            if ($deadlineParsed->gt($scheduledAtParsed)) {
                $validator->errors()->add('application_deadline', 'The application deadline must not be after the scheduled drive date.');
            }
        });
    }
}
