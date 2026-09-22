<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts, all sharing the password "Password@123" so the
 * README's "demo login credentials" table stays simple. Change this
 * before any non-local deployment.
 */
class UserSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'Password@123';

    public function run(): void
    {
        $roles = Role::pluck('id', 'name');
        $departments = Department::all();
        $batches = Batch::all();

        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@careerconnect.edu'],
            [
                'name' => 'Ananya Rao',
                'password' => Hash::make(self::DEMO_PASSWORD),
                'role_id' => $roles[RoleName::SUPER_ADMIN->value],
                'is_active' => true,
            ]
        );

        $placementAdmin = User::updateOrCreate(
            ['email' => 'placement.admin@careerconnect.edu'],
            [
                'name' => 'Rajesh Kumar',
                'password' => Hash::make(self::DEMO_PASSWORD),
                'role_id' => $roles[RoleName::PLACEMENT_ADMIN->value],
                'is_active' => true,
            ]
        );

        $facultyNames = ['Dr. Priya Sharma', 'Dr. Vikram Nair', 'Dr. Sunita Verma'];
        $facultyProfiles = [];

        foreach ($facultyNames as $index => $name) {
            $user = User::updateOrCreate(
                ['email' => 'faculty'.($index + 1).'@careerconnect.edu'],
                [
                    'name' => $name,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'role_id' => $roles[RoleName::FACULTY->value],
                    'is_active' => true,
                ]
            );

            $facultyProfiles[] = Faculty::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_code' => sprintf('FAC%03d', $index + 1),
                    'department_id' => $departments[$index % $departments->count()]->id,
                    'designation' => 'Assistant Professor',
                    'phone' => '90000000'.($index + 1),
                ]
            );
        }

        $studentNames = [
            'Aarav Mehta', 'Diya Patel', 'Kabir Singh', 'Isha Reddy', 'Rohan Gupta',
            'Sneha Iyer', 'Arjun Nair', 'Meera Joshi', 'Vivaan Das', 'Ananya Pillai',
        ];

        foreach ($studentNames as $index => $name) {
            $user = User::updateOrCreate(
                ['email' => 'student'.($index + 1).'@careerconnect.edu'],
                [
                    'name' => $name,
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'role_id' => $roles[RoleName::STUDENT->value],
                    'is_active' => true,
                ]
            );

            Student::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'roll_number' => sprintf('CC%02d%03d', 24, $index + 1),
                    'department_id' => $departments[$index % $departments->count()]->id,
                    'batch_id' => $batches[$index % $batches->count()]->id,
                    'phone' => '98765'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                    'faculty_mentor_id' => $facultyProfiles[$index % count($facultyProfiles)]->id,
                ]
            );
        }
    }
}
