<?php

namespace App\Http\Requests\Concerns;

use App\Enums\InterviewMode;

/**
 * Shared by StoreInterviewRequest/UpdateInterviewRequest/
 * RescheduleInterviewRequest: the mode-specific requirement on
 * meeting_url/venue (ONLINE requires meeting_url, OFFLINE requires
 * venue, HYBRID requires at least one of the two - required_without
 * each other) depends on which mode is actually being submitted, so
 * each Form Request computes $mode from its own input before calling
 * this. Passing null (no mode present/changing) yields the default,
 * unconditional 'nullable' rule for both fields - see each request's
 * own docblock for how "mode omitted" is handled differently between
 * Store (mode is always required) and Update/Reschedule (mode is
 * optional, and the mode-specific requirement only applies when the
 * caller is actually changing mode in that same request).
 */
trait ValidatesInterviewMode
{
    /**
     * @return array{meeting_url: array<int, string>, venue: array<int, string>}
     */
    private function modeSpecificRules(?InterviewMode $mode): array
    {
        $meetingUrlRule = match ($mode) {
            InterviewMode::ONLINE => 'required',
            InterviewMode::HYBRID => 'required_without:venue',
            default => 'nullable',
        };

        $venueRule = match ($mode) {
            InterviewMode::OFFLINE => 'required',
            InterviewMode::HYBRID => 'required_without:meeting_url',
            default => 'nullable',
        };

        return [
            'meeting_url' => [$meetingUrlRule, 'url', 'max:500'],
            'venue' => [$venueRule, 'string', 'max:255'],
        ];
    }
}
