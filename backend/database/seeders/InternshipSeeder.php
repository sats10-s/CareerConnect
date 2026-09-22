<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class InternshipSeeder extends Seeder
{
    public function run(): void
    {
        $companies = Company::all()->keyBy('name');
        $postedBy = User::where('role_id', Role::where('name', RoleName::PLACEMENT_ADMIN->value)->value('id'))->first();

        $internships = [
            ['title' => 'Frontend Engineering Intern', 'company' => 'NovaTech Solutions', 'duration_months' => 6, 'stipend' => '₹25,000/month'],
            ['title' => 'DevOps Intern', 'company' => 'Vertex Cloud Works', 'duration_months' => 3, 'stipend' => '₹20,000/month'],
            ['title' => 'Data Science Intern', 'company' => 'Quantum Byte Labs', 'duration_months' => 6, 'stipend' => '₹30,000/month'],
        ];

        foreach ($internships as $internship) {
            Internship::updateOrCreate(
                ['title' => $internship['title'], 'company_id' => $companies[$internship['company']]->id],
                [
                    'description' => "Placeholder internship description for {$internship['title']} at {$internship['company']}.",
                    'location' => 'Remote',
                    'duration_months' => $internship['duration_months'],
                    'stipend' => $internship['stipend'],
                    'posted_by' => $postedBy?->id,
                    'is_active' => true,
                ]
            );
        }
    }
}
