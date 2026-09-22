<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->student()->with(['department', 'batch'])->firstOrFail();

        $applications = $student->applications()->with('applicable')->latest('applied_at');
        $statusCounts = (clone $applications)->get()->countBy(fn ($app) => $app->status instanceof ApplicationStatus ? $app->status->value : $app->status);

        $profileFields = [
            $request->user()->name,
            $request->user()->email,
            $student->phone,
            $student->department_id,
            $student->batch_id,
            $student->roll_number,
        ];
        $filled = count(array_filter($profileFields, fn ($v) => ! empty($v)));
        $profileCompletion = (int) round(($filled / count($profileFields)) * 100);

        return response()->json([
            'stats' => [
                'applications' => $statusCounts->sum(),
                'shortlisted' => $statusCounts->get(ApplicationStatus::SHORTLISTED->value, 0),
                'interviews' => $statusCounts->get(ApplicationStatus::INTERVIEW->value, 0),
                'selected' => $statusCounts->get(ApplicationStatus::SELECTED->value, 0),
                'profile_completion' => $profileCompletion,
            ],
            'recent_applications' => (clone $applications)->take(5)->get()->map(fn ($app) => [
                'id' => $app->id,
                'title' => $app->applicable?->title,
                'company' => $app->applicable?->company?->name,
                'type' => class_basename($app->applicable_type) === 'Internship' ? 'internship' : 'job',
                'status' => $app->status instanceof ApplicationStatus ? $app->status->value : $app->status,
                'applied_at' => $app->applied_at,
            ]),
            // Notifications aren't a persisted feature yet (out of scope
            // for this foundation) - this is placeholder content only,
            // wired up to a real table in a later prompt.
            'notifications' => [
                ['id' => 1, 'message' => 'Welcome to CareerConnect! Complete your profile to get started.', 'read' => false],
            ],
        ]);
    }
}
