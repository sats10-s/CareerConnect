<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Department;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Small read-only endpoints the registration form needs (department /
 * batch pickers) that don't warrant a full CRUD resource yet.
 */
class MetaController extends Controller
{
    public function departments(): JsonResponse
    {
        return response()->json(
            Department::orderBy('name')->get(['id', 'name', 'code'])
        );
    }

    public function batches(): JsonResponse
    {
        return response()->json(
            Batch::orderByDesc('start_year')->get(['id', 'name', 'start_year', 'end_year'])
        );
    }

    /**
     * The public subset of institution settings the login/register screens
     * need (no auth). Everything else stays behind /api/super-admin/settings.
     */
    public function settings(SettingsService $settings): JsonResponse
    {
        $all = $settings->all();

        return response()->json([
            'institution_name' => $all['institution_name'],
            'support_email' => $all['support_email'],
            'allow_student_registration' => (bool) $all['allow_student_registration'],
        ]);
    }
}
