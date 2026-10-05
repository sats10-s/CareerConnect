<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\ApplicationStatus;
use App\Enums\InterviewStatus;
use App\Enums\OpportunityStatus;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyInterviewResource;
use App\Models\Application;
use App\Models\Interview;
use App\Models\Internship;
use App\Models\JobPosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/company/dashboard - behind `company.approved`. Counts for the
 * caller's own company only, plus the next few scheduled interviews.
 */
class DashboardController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $own = fn ($q) => $q->where('company_id', $company->id);

        $postings = collect([JobPosting::class, Internship::class])->flatMap(fn ($class) => $class::query()->where('company_id', $company->id)->get(['status', 'submitted_for_review_at']));
        $applications = Application::query()->whereHasMorph('applicable', [JobPosting::class, Internship::class], $own);
        $upcoming = Interview::query()
            ->whereHas('application', fn ($a) => $a->whereHasMorph('applicable', [JobPosting::class, Internship::class], $own))
            ->whereIn('status', [InterviewStatus::SCHEDULED->value, InterviewStatus::RESCHEDULED->value])
            ->where('scheduled_at', '>=', now())
            ->with(['application.student.user', 'application.student.department', 'application.student.batch', 'application.applicable.company'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();

        return $this->success(data: [
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status->value],
            'stats' => [
                'live_postings' => $postings->where('status', OpportunityStatus::PUBLISHED)->count(),
                'in_review' => $postings->filter(fn ($p) => $p->status === OpportunityStatus::DRAFT && $p->submitted_for_review_at !== null)->count(),
                'drafts' => $postings->filter(fn ($p) => $p->status === OpportunityStatus::DRAFT && $p->submitted_for_review_at === null)->count(),
                'applicants' => (clone $applications)->where('status', '!=', ApplicationStatus::WITHDRAWN->value)->count(),
                'shortlisted' => (clone $applications)->whereIn('status', [ApplicationStatus::SHORTLISTED->value, ApplicationStatus::ASSESSMENT->value, ApplicationStatus::INTERVIEW->value])->count(),
                'selected' => (clone $applications)->where('status', ApplicationStatus::SELECTED->value)->count(),
            ],
            'upcoming_interviews' => CompanyInterviewResource::collection($upcoming),
        ]);
    }
}
