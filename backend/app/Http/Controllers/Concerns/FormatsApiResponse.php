<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

/**
 * The exact {"success", "message", "data"|"errors"} envelope mandated
 * by the Jobs & Internships Backend Foundation module spec for its new
 * endpoints. Used ONLY by the controllers this module adds
 * (Api\Admin\JobPostingController, Api\Admin\InternshipController,
 * Api\Student\OpportunityController).
 *
 * IMPORTANT - this is NOT the convention the rest of this API uses.
 * Every pre-existing controller (AuthController, StudentProfileController
 * and the Api\Student\* profile controllers, all four Api\Dashboard\*
 * controllers) returns either a bare Eloquent-resource payload (e.g.
 * `{"data": {...}}` from a JsonResource, no "success" key) or, for
 * AdminDashboardController specifically, an unwrapped
 * `{"stats": ..., "recent_activity": ...}` with no envelope at all - a
 * repo-wide grep turns up zero existing occurrences of a "success" JSON
 * key anywhere under app/. The module spec described {success, message,
 * data} as "the existing API response convention", which this codebase
 * does not actually have; this trait follows the spec's explicit,
 * literal instruction for the NEW endpoints it defines, without
 * retrofitting the older ones. See the final report's "schema
 * decisions" section for this discrepancy.
 */
trait FormatsApiResponse
{
    protected function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    protected function failure(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }
}
