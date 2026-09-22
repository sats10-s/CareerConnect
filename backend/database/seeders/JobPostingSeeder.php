<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class JobPostingSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all()->keyBy('name');
        $postedBy = User::where('role_id', Role::where('name', RoleName::PLACEMENT_ADMIN->value)->value('id'))->first();

        $jobs = [
            ['title' => 'Software Engineer I', 'company' => 'NovaTech Solutions', 'location' => 'Bengaluru', 'salary_range' => '₹8-12 LPA'],
            ['title' => 'Cloud Support Engineer', 'company' => 'BrightPath Systems', 'location' => 'Hyderabad', 'salary_range' => '₹7-10 LPA'],
            ['title' => 'Machine Learning Engineer', 'company' => 'Quantum Byte Labs', 'location' => 'Pune', 'salary_range' => '₹10-15 LPA'],
            ['title' => 'Data Analyst', 'company' => 'Skyline Analytics', 'location' => 'Chennai', 'salary_range' => '₹6-9 LPA'],
        ];

        foreach ($jobs as $job) {
            JobPosting::updateOrCreate(
                ['title' => $job['title'], 'company_id' => $companies[$job['company']]->id],
                [
                    'description' => "Placeholder job description for {$job['title']} at {$job['company']}.",
                    'location' => $job['location'],
                    'salary_range' => $job['salary_range'],
                    'posted_by' => $postedBy?->id,
                    'is_active' => true,
                ]
            );
        }
    }
}
