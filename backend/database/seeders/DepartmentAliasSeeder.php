<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentAlias;
use Illuminate\Database\Seeder;

/**
 * The department spellings used in the college's student file. Creates any
 * missing department (matched by code, so an existing department keeps its
 * name) and points each alias at it. Safe to re-run.
 *
 *   php artisan db:seed --class=DepartmentAliasSeeder
 */
class DepartmentAliasSeeder extends Seeder
{
    private const DEPARTMENTS = [
        'AIDS' => ['name' => 'Artificial Intelligence & Data Science', 'aliases' => ['AI&DS', 'AIDS', 'AI DS']],
        'AIML' => ['name' => 'Artificial Intelligence & Machine Learning', 'aliases' => ['AIML', 'AI&ML']],
        'CSE' => ['name' => 'Computer Science & Engineering', 'aliases' => ['CSE']],
        'CIVIL' => ['name' => 'Civil Engineering', 'aliases' => ['CE', 'Civil']],
        'ECE' => ['name' => 'Electronics & Communication Engineering', 'aliases' => ['ECE']],
        'EEE' => ['name' => 'Electrical & Electronics Engineering', 'aliases' => ['EEE']],
        'IT' => ['name' => 'Information Technology', 'aliases' => ['IT']],
        'MECH' => ['name' => 'Mechanical Engineering', 'aliases' => ['ME', 'MECH', 'Mechanical']],
    ];

    public function run(): void
    {
        foreach (self::DEPARTMENTS as $code => $definition) {
            $department = Department::query()->where('code', $code)->first()
                ?? Department::query()->where('name', $definition['name'])->first()
                ?? Department::create(['code' => $code, 'name' => $definition['name']]);

            foreach ($definition['aliases'] as $alias) {
                DepartmentAlias::updateOrCreate(
                    ['alias' => DepartmentAlias::normalize($alias)],
                    ['department_id' => $department->id],
                );
            }
        }
    }
}
