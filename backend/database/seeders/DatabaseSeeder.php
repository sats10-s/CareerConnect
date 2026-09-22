<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            BatchSeeder::class,
            UserSeeder::class,
            CompanySeeder::class,
            JobPostingSeeder::class,
            InternshipSeeder::class,
            ApplicationSeeder::class,
        ]);
    }
}
