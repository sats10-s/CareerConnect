<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Company;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $recentApplications = Application::with(['student.user', 'applicable'])
            ->latest('applied_at')
            ->take(5)
            ->get()
            ->map(fn ($app) => [
                'type' => 'application',
                'description' => sprintf(
                    '%s applied to %s',
                    $app->student?->user?->name ?? 'A student',
                    $app->applicable?->title ?? 'an opportunity'
                ),
                'at' => $app->applied_at,
            ]);

        $recentCompanies = Company::latest()->take(3)->get()->map(fn ($c) => [
            'type' => 'company',
            'description' => "New company onboarded: {$c->name}",
            'at' => $c->created_at,
        ]);

        $recentActivity = $recentApplications->concat($recentCompanies)
            ->sortByDesc('at')
            ->values()
            ->take(8);

        return response()->json([
            'stats' => [
                'total_students' => Student::count(),
                'companies' => Company::count(),
                'active_jobs' => JobPosting::where('is_active', true)->count(),
                'active_internships' => Internship::where('is_active', true)->count(),
                'applications' => Application::count(),
                'selected_students' => Application::where('status', ApplicationStatus::SELECTED->value)
                    ->distinct('student_id')
                    ->count('student_id'),
            ],
            'recent_activity' => $recentActivity,
        ]);
    }
}
