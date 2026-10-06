<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a COMPANY-role login to its company. Null for every other role.
 * `company_member_role` is OWNER for the single login each company gets in
 * v1; the column exists so recruiter seats can be added later without
 * another users-table migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('role_id')->constrained('companies')->nullOnDelete();
            $table->string('company_member_role', 20)->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['company_id', 'company_member_role']);
        });
    }
};
