<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/company/dashboard - behind `company.approved`. A deliberately
 * small summary for now: posting CRUD, applicants and interviews for
 * recruiters are the next module.
 */
class DashboardController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->company->loadCount(['jobPostings', 'internships']);

        return $this->success(data: [
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status->value],
            'stats' => [
                'job_postings' => $company->job_postings_count,
                'internships' => $company->internships_count,
            ],
        ]);
    }
}
