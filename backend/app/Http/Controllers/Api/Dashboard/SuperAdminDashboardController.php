<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
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
                'audit_events' => AuditLog::count(),
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
