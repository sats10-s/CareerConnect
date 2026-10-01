<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Concerns\FormatsApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Super Admin > Security: sign-in activity and account health, computed
 * from the audit log, users table, Sanctum tokens and (with the database
 * session driver) the sessions table. Read-only - the actions it links to
 * (deactivate, reset password, sign out everywhere) live on Users.
 */
class SecurityController extends Controller
{
    use FormatsApiResponse;

    public function index(): JsonResponse
    {
        $dayAgo = now()->subDay();
        $weekAgo = now()->subDays(7);

        $usesDbSessions = config('session.driver') === 'database' && Schema::hasTable('sessions');
        $sessionCutoff = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();

        $failedByEmail = AuditLog::query()
            ->where('action', 'auth.login_failed')
            ->where('created_at', '>=', $weekAgo)
            ->get(['metadata', 'ip_address', 'created_at'])
            ->groupBy(fn ($log) => strtolower((string) ($log->metadata['email'] ?? 'unknown')))
            ->map(fn ($group, $email) => [
                'email' => $email,
                'attempts' => $group->count(),
                'last_attempt_at' => $group->max('created_at')?->toIso8601String(),
                'ip_addresses' => $group->pluck('ip_address')->filter()->unique()->values()->take(5),
            ])
            ->sortByDesc('attempts')
            ->values()
            ->take(10);

        $recent = AuditLog::query()
            ->whereIn('action', ['auth.login', 'auth.login_failed', 'auth.login_blocked', 'user.deactivated', 'user.password_reset', 'user.sessions_revoked'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return $this->success(data: [
            'stats' => [
                'successful_logins_24h' => AuditLog::where('action', 'auth.login')->where('created_at', '>=', $dayAgo)->count(),
                'failed_logins_24h' => AuditLog::where('action', 'auth.login_failed')->where('created_at', '>=', $dayAgo)->count(),
                'failed_logins_7d' => AuditLog::where('action', 'auth.login_failed')->where('created_at', '>=', $weekAgo)->count(),
                'blocked_logins_7d' => AuditLog::where('action', 'auth.login_blocked')->where('created_at', '>=', $weekAgo)->count(),
                'inactive_accounts' => User::where('is_active', false)->count(),
                'never_signed_in' => User::whereNull('last_login_at')->count(),
                'api_tokens' => PersonalAccessToken::count(),
                'web_sessions' => $usesDbSessions ? DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', $sessionCutoff)->count() : null,
            ],
            'failed_by_account' => $failedByEmail,
            'recent_events' => $recent,
            'policy' => [
                'password_min_length' => 8,
                'password_rules' => ['At least 8 characters', 'Upper- and lower-case letters', 'At least one number'],
                'session_lifetime_minutes' => (int) config('session.lifetime', 120),
                'deactivation_signs_out' => true,
            ],
        ]);
    }
}
