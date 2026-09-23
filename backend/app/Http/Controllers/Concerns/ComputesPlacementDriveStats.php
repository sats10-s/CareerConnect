<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ApplicationStatus;
use App\Models\PlacementDrive;
use Illuminate\Support\Facades\DB;

/**
 * Application/selected/interview counts for one or many placement
 * drives, always derived from the EXISTING applications/interviews
 * tables by walking placement_drive_opportunities -> applications ->
 * interviews - never a second application/interview system, and never
 * a per-drive N+1: exactly one grouped query for application/selected
 * counts (regardless of how many drives are in $drives) and, when
 * requested, one more for interview counts.
 *
 * `applications.applicable_type`/`applicable_id` and
 * `placement_drive_opportunities.opportunity_type`/`opportunity_id`
 * both store the full model class name (see
 * PlacementDriveOpportunity's docblock), so the two tables join
 * directly on matching (type, id) pairs with no extra lookup table
 * needed.
 */
trait ComputesPlacementDriveStats
{
    /**
     * Sets `application_count`/`selected_count` (and, when
     * $withInterviews is true, `interview_count`) as plain dynamic
     * attributes on each given PlacementDrive - read directly by
     * PlacementDriveResource/PlacementDriveListResource, never stored
     * on the model.
     *
     * @param iterable<PlacementDrive> $drives
     */
    private function attachDriveStats(iterable $drives, bool $withInterviews = false): void
    {
        $drives = collect($drives);
        $ids = $drives->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $counts = DB::table('placement_drive_opportunities as pdo')
            ->join('applications as a', function ($join) {
                $join->on('a.applicable_type', '=', 'pdo.opportunity_type')
                    ->on('a.applicable_id', '=', 'pdo.opportunity_id');
            })
            ->whereIn('pdo.placement_drive_id', $ids)
            ->selectRaw(
                'pdo.placement_drive_id as placement_drive_id, COUNT(*) as application_count, SUM(CASE WHEN a.status = ? THEN 1 ELSE 0 END) as selected_count',
                [ApplicationStatus::SELECTED->value],
            )
            ->groupBy('pdo.placement_drive_id')
            ->get()
            ->keyBy('placement_drive_id');

        $interviewCounts = collect();

        if ($withInterviews) {
            $interviewCounts = DB::table('placement_drive_opportunities as pdo')
                ->join('applications as a', function ($join) {
                    $join->on('a.applicable_type', '=', 'pdo.opportunity_type')
                        ->on('a.applicable_id', '=', 'pdo.opportunity_id');
                })
                ->join('interviews as i', 'i.application_id', '=', 'a.id')
                ->whereIn('pdo.placement_drive_id', $ids)
                ->selectRaw('pdo.placement_drive_id as placement_drive_id, COUNT(*) as interview_count')
                ->groupBy('pdo.placement_drive_id')
                ->get()
                ->keyBy('placement_drive_id');
        }

        foreach ($drives as $drive) {
            $row = $counts->get($drive->id);
            $drive->setAttribute('application_count', (int) ($row->application_count ?? 0));
            $drive->setAttribute('selected_count', (int) ($row->selected_count ?? 0));

            if ($withInterviews) {
                $interviewRow = $interviewCounts->get($drive->id);
                $drive->setAttribute('interview_count', (int) ($interviewRow->interview_count ?? 0));
            }
        }
    }
}
