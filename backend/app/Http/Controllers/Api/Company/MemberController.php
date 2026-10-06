<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\CompanyMemberRole;
use App\Enums\RoleName;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\StoreCompanyMemberRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * /api/company/members - the company's logins. Every member can see the
 * team; only the OWNER adds RECRUITER logins or deactivates / reactivates
 * them (CompanyPolicy::manageMembers). Members always belong to the
 * caller's own company, and the owner's own login can't be deactivated here.
 */
class MemberController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $members = User::query()->where('company_id', $request->user()->company_id)->orderBy('created_at')->get();

        return $this->success(data: [
            'items' => $members->map(fn (User $member) => $this->present($member, $request->user())),
            'can_manage' => Gate::allows('manageMembers', $request->user()->company),
        ]);
    }

    public function store(StoreCompanyMemberRequest $request): JsonResponse
    {
        $company = $request->user()->company;
        Gate::authorize('manageMembers', $company);

        $member = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'role_id' => Role::where('name', RoleName::COMPANY->value)->firstOrFail()->id,
            'company_id' => $company->id,
            'company_member_role' => CompanyMemberRole::RECRUITER,
            'is_active' => true,
        ]);
        $this->audit->record('company.member_added', "{$request->user()->name} added recruiter {$member->email} to {$company->name}", $company, ['member_id' => $member->id]);

        return $this->success(data: $this->present($member, $request->user()), message: 'Recruiter added. Share the sign-in details with them directly.', status: 201);
    }

    public function updateStatus(Request $request, int $member): JsonResponse
    {
        $company = $request->user()->company;
        Gate::authorize('manageMembers', $company);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $target = User::query()->where('company_id', $company->id)->findOrFail($member);
        if ($target->company_member_role === CompanyMemberRole::OWNER) {
            return $this->failure("The owner's login can't be deactivated here.", ['is_active' => ["The owner's login can't be deactivated here."]]);
        }

        $target->forceFill(['is_active' => $data['is_active']])->save();
        if (! $data['is_active']) {
            $target->tokens()->delete();
            if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }
        }
        $this->audit->record($data['is_active'] ? 'company.member_activated' : 'company.member_deactivated', ($data['is_active'] ? 'Reactivated' : 'Deactivated')." recruiter {$target->email}", $company, ['member_id' => $target->id]);

        return $this->success(data: $this->present($target, $request->user()), message: $data['is_active'] ? 'Recruiter reactivated.' : 'Recruiter deactivated and signed out.');
    }

    /** @return array<string, mixed> */
    private function present(User $member, User $viewer): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'member_role' => $member->company_member_role?->value,
            'is_active' => (bool) $member->is_active,
            'is_self' => $member->id === $viewer->id,
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }
}
