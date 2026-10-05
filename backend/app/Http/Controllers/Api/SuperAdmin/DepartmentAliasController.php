<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DepartmentAlias;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin > Student Data > Settings: the department spellings the college file uses. */
class DepartmentAliasController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $aliases = DepartmentAlias::query()->with('department:id,name,code')->orderBy('alias')->get();

        return $this->success(data: $aliases->map(fn (DepartmentAlias $alias) => $this->present($alias))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:60'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
        ]);

        $key = DepartmentAlias::normalize($data['alias']);
        if ($key === '') {
            return $this->failure('Enter the spelling used in the college file.', ['alias' => ['Enter the spelling used in the college file.']]);
        }

        $alias = DepartmentAlias::updateOrCreate(['alias' => $key], ['department_id' => $data['department_id']]);
        $alias->load('department:id,name,code');
        $this->audit->record('department_alias.created', "Mapped \"{$key}\" to {$alias->department->name}", $alias);

        return $this->success(data: $this->present($alias), message: 'Alias saved.', status: 201);
    }

    public function destroy(DepartmentAlias $alias): JsonResponse
    {
        $alias->delete();
        $this->audit->record('department_alias.deleted', "Removed department alias \"{$alias->alias}\"");

        return $this->success(message: 'Alias removed.');
    }

    private function present(DepartmentAlias $alias): array
    {
        return [
            'id' => $alias->id,
            'alias' => $alias->alias,
            'department' => ['id' => $alias->department->id, 'name' => $alias->department->name, 'code' => $alias->department->code],
        ];
    }
}
