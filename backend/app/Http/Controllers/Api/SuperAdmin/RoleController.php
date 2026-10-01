<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Enums\RoleName;
use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Super Admin > Roles: the four fixed roles with live member counts and a
 * plain description of what each can do. Roles are defined in code (route
 * middleware and policies), so there is deliberately nothing to edit here.
 */
class RoleController extends Controller
{
    use FormatsApiResponse;

    public function index(): JsonResponse
    {
        $counts = User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->selectRaw('roles.name as role, COUNT(*) as total, SUM(CASE WHEN users.is_active THEN 1 ELSE 0 END) as active')
            ->groupBy('roles.name')
            ->get()
            ->keyBy('role');

        $items = array_map(fn (RoleName $role) => [
            'name' => $role->value,
            'label' => $role->label(),
            'users_count' => (int) ($counts[$role->value]->total ?? 0),
            'active_count' => (int) ($counts[$role->value]->active ?? 0),
            'capabilities' => $role->capabilities(),
        ], RoleName::cases());

        return $this->success(data: ['items' => $items]);
    }
}
