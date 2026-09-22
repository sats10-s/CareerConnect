<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Internship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultyDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $faculty = $request->user()->faculty()->with('department')->firstOrFail();

        $mentees = $faculty->mentees()->with(['user', 'department', 'batch']);

        return response()->json([
            'stats' => [
                'assigned_students' => (clone $mentees)->count(),
                'active_internships' => Internship::where('is_active', true)->count(),
                // Attendance / weekly reports are intentionally out of
                // scope for this foundation (see project README) -
                // these are placeholder zeros until that feature ships.
                'pending_reports' => 0,
                'attendance_alerts' => 0,
            ],
            'students' => (clone $mentees)->take(10)->get()->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->user?->name,
                'roll_number' => $s->roll_number,
                'department' => $s->department?->name,
                'batch' => $s->batch?->name,
            ]),
        ]);
    }
}
