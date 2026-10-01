<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Enums\RoleName;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\StoreUserRequest;
use App\Http\Requests\SuperAdmin\UpdateUserRequest;
use App\Http\Resources\SuperAdminUserResource;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Super Admin > Users (route-gated by role:SUPER_ADMIN). Every write is
 * recorded in the audit log. Guard rails, enforced here rather than in the
 * UI: you can't deactivate, demote or sign out yourself, and the last active
 * Super Admin can't be deactivated or demoted.
 */
class UserController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', Rule::in(RoleName::values())],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'inactive'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = User::query()->with(['role', 'student.department', 'student.batch', 'faculty.department'])->withCount('tokens');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($role = $filters['role'] ?? null) {
            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }
        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        $users = $query->latest('id')->paginate((int) ($filters['per_page'] ?? 15));

        return $this->success(data: [
            'items' => SuperAdminUserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load(['role', 'student.department', 'student.batch', 'faculty.department'])->loadCount('tokens');

        return $this->success(data: new SuperAdminUserResource($user));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = Role::where('name', $data['role'])->firstOrFail();

        $user = DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role_id' => $role->id,
                'is_active' => true,
            ]);

            if ($data['role'] === RoleName::FACULTY->value) {
                Faculty::create([
                    'user_id' => $user->id,
                    'employee_code' => $data['employee_code'],
                    'department_id' => $data['department_id'],
                    'designation' => $data['designation'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ]);
            }

            return $user;
        });

        $this->audit->record('user.created', "Created {$role->label} account for {$user->name} ({$user->email})", $user, ['role' => $data['role']]);
        $user->load(['role', 'faculty.department'])->loadCount('tokens');

        return $this->success(data: new SuperAdminUserResource($user), message: 'Account created.', status: 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $currentRole = $user->roleName();

        if (isset($data['role']) && $data['role'] !== $currentRole?->value) {
            if (! in_array($currentRole, [RoleName::PLACEMENT_ADMIN, RoleName::SUPER_ADMIN], true)) {
                return $this->failure('Students and faculty keep their role - their records depend on it.', ['role' => ['Students and faculty keep their role - their records depend on it.']]);
            }
            if ($user->id === $request->user()->id) {
                return $this->failure("You can't change your own role.", ['role' => ["You can't change your own role."]]);
            }
            if ($currentRole === RoleName::SUPER_ADMIN && $this->activeSuperAdminCount() <= 1) {
                return $this->failure('This is the only active Super Admin, so it must stay a Super Admin.', ['role' => ['This is the only active Super Admin.']]);
            }
        }

        $facultyFields = array_intersect_key($data, array_flip(['employee_code', 'department_id', 'designation', 'phone']));
        if ($facultyFields && ! $user->faculty) {
            return $this->failure('Only faculty accounts have an employee code, department, designation and phone.', []);
        }

        $changed = [];
        DB::transaction(function () use ($user, $data, $facultyFields, &$changed) {
            $user->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            if (isset($data['role'])) {
                $user->role_id = Role::where('name', $data['role'])->firstOrFail()->id;
            }
            $changed = array_keys($user->getDirty());
            $user->save();

            if ($facultyFields) {
                $user->faculty->fill($facultyFields);
                $changed = array_merge($changed, array_keys($user->faculty->getDirty()));
                $user->faculty->save();
            }
        });

        if ($changed) {
            $this->audit->record('user.updated', "Updated {$user->name}'s account", $user, ['fields' => array_values(array_unique($changed))]);
        }

        $user->load(['role', 'student.department', 'student.batch', 'faculty.department'])->loadCount('tokens');

        return $this->success(data: new SuperAdminUserResource($user), message: 'Account updated.');
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $activate = (bool) $data['is_active'];

        if ($user->id === $request->user()->id) {
            return $this->failure("You can't change your own account's status.", []);
        }
        if (! $activate && $user->roleName() === RoleName::SUPER_ADMIN && $user->is_active && $this->activeSuperAdminCount() <= 1) {
            return $this->failure("This is the only active Super Admin - it can't be deactivated.", []);
        }

        if ($user->is_active !== $activate) {
            $user->forceFill(['is_active' => $activate])->save();
            if (! $activate) {
                $this->revokeAccess($user);
            }
            $this->audit->record($activate ? 'user.activated' : 'user.deactivated', ($activate ? 'Activated ' : 'Deactivated ')."{$user->name} ({$user->email})", $user);
        }

        $user->load(['role', 'student.department', 'student.batch', 'faculty.department'])->loadCount('tokens');

        return $this->success(data: new SuperAdminUserResource($user), message: $activate ? 'Account activated.' : 'Account deactivated and signed out everywhere.');
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $request->validate(['password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);

        $user->forceFill(['password' => $request->input('password')])->save();
        if ($user->id !== $request->user()->id) {
            $this->revokeAccess($user);
        }
        $this->audit->record('user.password_reset', "Reset the password for {$user->name}", $user);

        return $this->success(message: $user->id === $request->user()->id ? 'Password changed.' : 'Password reset. The user has been signed out everywhere.');
    }

    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->failure("You can't sign yourself out from here - use Sign out instead.", []);
        }

        $count = $this->revokeAccess($user);
        $this->audit->record('user.sessions_revoked', "Signed {$user->name} out of every session", $user, ['sessions' => $count]);

        return $this->success(data: ['revoked' => $count], message: 'Signed out of every session.');
    }

    /** Delete API tokens and (database-driver) web sessions; returns how many were removed. */
    private function revokeAccess(User $user): int
    {
        $count = $user->tokens()->delete();
        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            $count += DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        return $count;
    }

    private function activeSuperAdminCount(): int
    {
        return User::where('is_active', true)->whereHas('role', fn ($q) => $q->where('name', RoleName::SUPER_ADMIN->value))->count();
    }
}
