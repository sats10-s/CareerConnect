<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared by Admin\JobPostingController and Admin\InternshipController:
 * translates the flat, admin-facing eligibility fields on a create/
 * update request (`eligibility_enforced`, `eligible_department_ids`,
 * `eligible_batch_ids`, `eligible_years`, `minimum_cgpa`,
 * `maximum_backlogs`, `required_skills`) into the internal shape
 * HasEligibilityCriteria::syncEligibility() expects. Only keys that
 * were actually present on the request are included, so
 * syncEligibility()'s "omitted = leave unchanged" partial-update
 * semantics are preserved end-to-end from the HTTP layer down.
 */
trait BuildsEligibilityPayload
{
    /**
     * @return array{eligibility_enforced?:bool,min_cgpa?:float|null,max_backlogs?:int|null,department_ids?:int[],batch_ids?:int[],years?:int[],skills?:string[]}
     */
    private function eligibilityPayloadFromRequest(Request $request): array
    {
        $eligibility = [];

        if ($request->has('eligibility_enforced')) {
            $eligibility['eligibility_enforced'] = $request->boolean('eligibility_enforced');
        }

        if ($request->has('minimum_cgpa')) {
            $eligibility['min_cgpa'] = $request->input('minimum_cgpa');
        }

        if ($request->has('maximum_backlogs')) {
            $eligibility['max_backlogs'] = $request->input('maximum_backlogs');
        }

        if ($request->has('eligible_department_ids')) {
            $eligibility['department_ids'] = $request->input('eligible_department_ids', []);
        }

        if ($request->has('eligible_batch_ids')) {
            $eligibility['batch_ids'] = $request->input('eligible_batch_ids', []);
        }

        if ($request->has('eligible_years')) {
            $eligibility['years'] = $request->input('eligible_years', []);
        }

        if ($request->has('required_skills')) {
            $eligibility['skills'] = $request->input('required_skills', []);
        }

        return $eligibility;
    }

    private function syncPlacedStudentPolicy(Request $request, \App\Models\JobPosting|\App\Models\Internship $opportunity): void
    {
        if (! $request->has('allow_placed_students')) {
            return;
        }

        DB::table('opportunity_placement_policies')->updateOrInsert(
            ['opportunity_type' => $opportunity::class, 'opportunity_id' => $opportunity->id],
            ['allow_placed_students' => $request->boolean('allow_placed_students'), 'updated_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
