<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $companies = [
            ['name' => 'NovaTech Solutions', 'industry' => 'Software', 'website' => 'https://novatech.example.com'],
            ['name' => 'BrightPath Systems', 'industry' => 'Cloud Infrastructure', 'website' => 'https://brightpath.example.com'],
            ['name' => 'Quantum Byte Labs', 'industry' => 'Artificial Intelligence', 'website' => 'https://quantumbyte.example.com'],
            ['name' => 'Skyline Analytics', 'industry' => 'Data & Analytics', 'website' => 'https://skylineanalytics.example.com'],
            ['name' => 'Vertex Cloud Works', 'industry' => 'DevOps & Cloud', 'website' => 'https://vertexcloud.example.com'],
        ];

        foreach ($companies as $company) {
            Company::updateOrCreate(
                ['name' => $company['name']],
                $company + ['description' => "{$company['name']} is a fictional demo company used for CareerConnect seed data.", 'is_active' => true]
            );
        }
    }
}
