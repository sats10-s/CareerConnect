<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruiter-created postings. `source` is ADMIN for everything the
 * placement office posts (the default, so existing rows are unchanged) and
 * COMPANY for postings a recruiter created from the company portal.
 * `submitted_for_review_at` marks a COMPANY draft the recruiter has handed
 * to the placement office to publish (company_postings_require_review).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['job_postings', 'internships'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('source', 20)->default('ADMIN')->after('status');
                $table->timestamp('submitted_for_review_at')->nullable()->after('source');
                $table->index('source');
            });
        }
    }

    public function down(): void
    {
        foreach (['job_postings', 'internships'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['source']);
                $table->dropColumn(['source', 'submitted_for_review_at']);
            });
        }
    }
};
