<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SuperAdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $adminRoleIds = Role::whereIn('name', [RoleName::PLACEMENT_ADMIN->value, RoleName::SUPER_ADMIN->value])
            ->pluck('id');

        return response()->json([
            'stats' => [
                'users' => User::count(),
                'students' => Student::count(),
                'faculty' => Faculty::count(),
                'admins' => User::whereIn('role_id', $adminRoleIds)->count(),
                'active_accounts' => User::where('is_active', true)->count(),
                // Audit logging is a placeholder nav item for this
                // foundation - no audit_logs table exists yet, so this
                // is a static 0 until that feature is built.
                'audit_events' => 0,
            ],
            'recent_users' => User::with('role')->latest()->take(8)->get()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'is_active' => $u->is_active,
                'created_at' => $u->created_at,
            ]),
        ]);
    }
}
