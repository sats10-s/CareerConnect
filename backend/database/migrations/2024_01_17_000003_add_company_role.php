<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the COMPANY role row to databases that were already seeded. A fresh
 * database (including the test suite's) has an empty roles table at this
 * point and gets every role, COMPANY included, from RoleSeeder instead -
 * inserting here too would collide with that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->count() === 0 || DB::table('roles')->where('name', 'COMPANY')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => 'COMPANY',
            'label' => 'Company Recruiter',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Left in place: users may already reference the role.
    }
};
