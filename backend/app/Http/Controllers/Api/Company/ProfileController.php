<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanyProfileRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/company/me and PATCH /api/company/profile - the two company
 * routes that stay open while a registration is PENDING (or REJECTED /
 * SUSPENDED), so the recruiter can see their status and fix their details.
 * The company is always the authenticated user's own.
 */
class ProfileController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $company = $this->ownCompany($request);
        $company->load('owner');

        return $this->success(data: new CompanyResource($company));
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = $this->ownCompany($request);

        $company->fill($request->validated());
        $changed = array_keys($company->getDirty());
        $company->save();
        if ($changed) {
            $this->audit->record('company.profile_updated', "{$request->user()->name} updated {$company->name}'s profile", $company, ['fields' => $changed]);
        }
        $company->load('owner');

        return $this->success(data: new CompanyResource($company), message: 'Company profile updated.');
    }

    private function ownCompany(Request $request): Company
    {
        $company = $request->user()->company;
        abort_unless($company, 403, 'This account is not linked to a company.');
        Gate::authorize('manageOwn', $company);

        return $company;
    }
}
