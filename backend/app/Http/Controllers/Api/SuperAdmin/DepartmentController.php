<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin > Departments. A department can only be deleted while no
 * student or faculty member belongs to it (the foreign keys restrict it
 * anyway; this returns a readable message instead of a database error).
 */
class DepartmentController extends Controller
{
    use FormatsApiResponse;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $departments = Department::query()->withCount(['students', 'faculty'])->orderBy('name')->get();

        return $this->success(data: ['items' => $departments->map(fn (Department $d) => $this->present($d))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $department = Department::create($data);
        $this->audit->record('department.created', "Created department {$department->name} ({$department->code})", $department);

        return $this->success(data: $this->present($department->loadCount(['students', 'faculty'])), message: 'Department added.', status: 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $department->fill($this->validated($request, $department));
        $changed = array_keys($department->getDirty());
        $department->save();
        if ($changed) {
            $this->audit->record('department.updated', "Updated department {$department->name}", $department, ['fields' => $changed]);
        }

        return $this->success(data: $this->present($department->loadCount(['students', 'faculty'])), message: 'Department updated.');
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->loadCount(['students', 'faculty']);
        if ($department->students_count + $department->faculty_count > 0) {
            return $this->failure("{$department->name} still has {$department->students_count} student(s) and {$department->faculty_count} faculty member(s), so it can't be deleted.", [], 409);
        }

        $name = $department->name;
        $department->delete();
        $this->audit->record('department.deleted', "Deleted department {$name}", null, ['id' => $department->id, 'name' => $name]);

        return $this->success(message: 'Department deleted.');
    }

    private function validated(Request $request, ?Department $department = null): array
    {
        $request->merge(array_filter([
            'name' => is_string($request->name) ? trim($request->name) : null,
            'code' => is_string($request->code) ? strtoupper(trim($request->code)) : null,
            'hod_email' => is_string($request->hod_email) ? (trim($request->hod_email) ?: null) : null,
        ], fn ($v) => $v !== null));

        return $request->validate([
            'name' => [$department ? 'sometimes' : 'required', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department)],
            'code' => [$department ? 'sometimes' : 'required', 'string', 'max:16', 'regex:/^[A-Z0-9&\-]+$/', Rule::unique('departments', 'code')->ignore($department)],
            // The HOD who gets this department's weekly placement summary.
            'hod_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ], [
            'name.unique' => 'A department with this name already exists.',
            'code.unique' => 'Another department already uses this code.',
            'code.regex' => 'Use capital letters, numbers, "&" or "-" only (e.g. CSE).',
        ]);
    }

    private function present(Department $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'hod_email' => $d->hod_email,
            'students_count' => (int) ($d->students_count ?? 0),
            'faculty_count' => (int) ($d->faculty_count ?? 0),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
