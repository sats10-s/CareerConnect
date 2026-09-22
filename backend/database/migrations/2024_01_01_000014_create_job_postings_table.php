<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder table for full-time job postings. Named `job_postings`
 * (model JobPosting) rather than `jobs` to avoid colliding with
 * Laravel's own queue "jobs" table naming convention. Full workflow
 * (eligibility rules, rounds, etc.) is out of scope for this
 * foundation and will be built in a later prompt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location', 150)->nullable();
            $table->string('salary_range', 100)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('company_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
