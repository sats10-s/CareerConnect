<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Computer Science & Engineering', 'code' => 'CSE'],
            ['name' => 'Information Technology', 'code' => 'IT'],
            ['name' => 'Electronics & Communication Engineering', 'code' => 'ECE'],
            ['name' => 'Electrical & Electronics Engineering', 'code' => 'EEE'],
            ['name' => 'Mechanical Engineering', 'code' => 'MECH'],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(['code' => $department['code']], $department);
        }
    }
}
