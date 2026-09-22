<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\Student;
use Illuminate\Database\Seeder;

class ApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $students = Student::orderBy('id')->get();
        $jobs = JobPosting::all();
        $internships = Internship::all();

        $statusCycle = [
            ApplicationStatus::APPLIED,
            ApplicationStatus::SHORTLISTED,
            ApplicationStatus::INTERVIEW,
            ApplicationStatus::SELECTED,
            ApplicationStatus::REJECTED,
        ];

        $opportunities = $jobs->map(fn ($j) => ['model' => $j, 'class' => JobPosting::class])
            ->concat($internships->map(fn ($i) => ['model' => $i, 'class' => Internship::class]))
            ->values();

        $cursor = 0;

        foreach ($students as $index => $student) {
            // Each of the first 8 students gets 1-2 sample applications;
            // the last 2 are left with none so the "no applications yet"
            // UI state is also exercised.
            if ($index >= 8) {
                continue;
            }

            $applicationsForThisStudent = ($index % 2 === 0) ? 2 : 1;

            for ($i = 0; $i < $applicationsForThisStudent; $i++) {
                $opportunity = $opportunities[$cursor % $opportunities->count()];
                $status = $statusCycle[$cursor % count($statusCycle)];
                $cursor++;

                Application::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'applicable_type' => $opportunity['class'],
                        'applicable_id' => $opportunity['model']->id,
                    ],
                    [
                        'status' => $status->value,
                        'applied_at' => now()->subDays(random_int(1, 30)),
                    ]
                );
            }
        }
    }
}
