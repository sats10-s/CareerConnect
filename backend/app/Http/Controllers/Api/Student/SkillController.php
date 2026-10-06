<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Api\Student\Concerns\ReturnsFreshProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Student\StoreSkillRequest;
use App\Http\Resources\StudentProfileResource;
use App\Models\OpportunityRequiredSkill;
use App\Models\StudentSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A student's own skills. Only POST (add) and DELETE (remove) exist -
 * editing a skill is delete + re-add, not an update endpoint - per the
 * module spec ("multiple individual skills...Add/remove").
 */
class SkillController extends Controller
{
    use ReturnsFreshProfile;

    public function store(StoreSkillRequest $request): StudentProfileResource
    {
        $request->user()->student->skills()->create($request->validated());

        return $this->freshProfile($request);
    }

    /**
     * Type-ahead for the skill input: names other students and opportunity
     * requirements already use, most common first, matched case-insensitively
     * and grouped so "react"/"React" appear once. Skills the student already
     * has are left out.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $query = mb_strtolower(trim((string) ($validated['q'] ?? '')));
        $own = $request->user()->student->skills->map(fn ($skill) => mb_strtolower($skill->skill_name))->all();

        $names = StudentSkill::query()->select('skill_name')
            ->when($query !== '', fn ($q) => $q->whereRaw('LOWER(skill_name) LIKE ?', ['%'.addcslashes($query, '%_\\').'%']))
            ->limit(2000)->pluck('skill_name')
            ->merge(OpportunityRequiredSkill::query()
                ->when($query !== '', fn ($q) => $q->whereRaw('LOWER(skill_name) LIKE ?', ['%'.addcslashes($query, '%_\\').'%']))
                ->limit(2000)->pluck('skill_name'));

        $suggestions = $names
            ->map(fn ($name) => trim((string) preg_replace('/\s+/u', ' ', $name)))
            ->filter(fn ($name) => $name !== '' && mb_strlen($name) <= StoreSkillRequest::MAX_LENGTH)
            ->groupBy(fn ($name) => mb_strtolower($name))
            ->reject(fn ($group, $key) => in_array($key, $own, true))
            ->sortByDesc(fn ($group, $key) => [str_starts_with($key, $query) ? 1 : 0, $group->count()])
            ->map(fn ($group) => $group->countBy()->sortDesc()->keys()->first())
            ->take(10)
            ->values();

        return response()->json(['data' => $suggestions]);
    }

    public function destroy(Request $request, StudentSkill $skill): StudentProfileResource
    {
        // IDOR guard: {skill} above binds to ANY skill row by id, so this
        // authorize() call - backed by StudentSkillPolicy - is what stops
        // a student deleting another student's skill by changing the id
        // in the URL. A skill owned by someone else yields 403.
        Gate::authorize('delete', $skill);

        $skill->delete();

        return $this->freshProfile($request);
    }
}
