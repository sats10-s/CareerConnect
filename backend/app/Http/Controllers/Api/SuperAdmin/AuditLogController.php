<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Super Admin > Audit Logs: read-only, newest first, filterable. */
class AuditLogController extends Controller
{
    use FormatsApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'action' => ['sometimes', 'nullable', Rule::in(array_keys(AuditLogger::ACTIONS))],
            'area' => ['sometimes', 'nullable', Rule::in(['auth', 'user', 'department', 'batch', 'company', 'settings'])],
            'actor_id' => ['sometimes', 'nullable', 'integer'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $query = AuditLog::query();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('description', 'like', "%{$search}%")->orWhere('actor_name', 'like', "%{$search}%")->orWhere('ip_address', 'like', "%{$search}%"));
        }
        if ($action = $filters['action'] ?? null) {
            $query->where('action', $action);
        }
        if ($area = $filters['area'] ?? null) {
            $query->where('action', 'like', "{$area}.%");
        }
        if ($actorId = $filters['actor_id'] ?? null) {
            $query->where('actor_id', $actorId);
        }
        if ($from = $filters['date_from'] ?? null) {
            $query->where('created_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }
        if ($to = $filters['date_to'] ?? null) {
            $query->where('created_at', '<=', CarbonImmutable::parse($to)->endOfDay());
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return $this->success(data: [
            'items' => collect($logs->items())->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => AuditLogger::ACTIONS[$log->action] ?? $log->action,
                'description' => $log->description,
                'actor' => $log->actor_name ? ['id' => $log->actor_id, 'name' => $log->actor_name, 'role' => $log->actor_role] : null,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
            'actions' => collect(AuditLogger::ACTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }
}
