<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail. Recording must never break the action being
 * audited, so a failure to write is logged and swallowed.
 *
 * Action names are dotted `area.verb` strings; ACTIONS is the full list the
 * UI can filter on.
 */
class AuditLogger
{
    public const ACTIONS = [
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Failed sign-in',
        'auth.login_blocked' => 'Sign-in blocked (inactive account)',
        'auth.logout' => 'Signed out',
        'auth.registered' => 'Student registered',
        'auth.registration_blocked' => 'Registration blocked (closed)',
        'auth.login_locked' => 'Sign-in locked (too many attempts)',
        'auth.password_changed' => 'Password changed',
        'auth.password_reset_requested' => 'Password reset link requested',
        'auth.password_reset_completed' => 'Password reset by link',
        'student.import_uploaded' => 'Student data file uploaded',
        'student.import_confirmed' => 'Student data import confirmed',
        'student.import_cancelled' => 'Student data import cancelled',
        'student.import_rolled_back' => 'Student data import rolled back',
        'student.academics_updated' => 'Student academics updated',
        'student.password_reset' => 'Student password reset to first-login',
        'student.correction_requested' => 'Student reported a correction',
        'department_alias.created' => 'Department alias added',
        'department_alias.deleted' => 'Department alias removed',
        'user.created' => 'User created',
        'user.updated' => 'User updated',
        'user.activated' => 'User activated',
        'user.deactivated' => 'User deactivated',
        'user.password_reset' => 'Password reset',
        'user.sessions_revoked' => 'Sessions signed out',
        'department.created' => 'Department created',
        'department.updated' => 'Department updated',
        'department.deleted' => 'Department deleted',
        'batch.created' => 'Batch created',
        'batch.updated' => 'Batch updated',
        'batch.deleted' => 'Batch deleted',
        'company.created' => 'Company created',
        'company.updated' => 'Company updated',
        'settings.updated' => 'Settings updated',
    ];

    public function record(string $action, string $description, ?Model $subject = null, array $metadata = [], ?User $actor = null): void
    {
        try {
            $actor ??= Auth::user();
            $request = request();

            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_role' => $actor?->roleName()?->value,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'description' => mb_substr($description, 0, 500),
                'metadata' => $metadata ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log write failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
