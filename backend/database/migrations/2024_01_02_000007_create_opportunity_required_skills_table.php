<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Required skills for a JobPosting/Internship - deliberately a
 * free-text `skill_name` column (not a foreign key to a master skills
 * table), mirroring the existing `student_skills` table exactly
 * (2024_01_01_000018_create_student_skills_table has the same
 * skill_name-as-free-text shape) since no master Skill lookup table
 * exists anywhere in this codebase. One opportunity can require
 * multiple skills - each is its own row here, never a comma-separated
 * or JSON list.
 *
 * `morphs('opportunity')` is given an explicit index name - see
 * 2024_01_02_000003_create_opportunity_eligibility_criteria_table's
 * docblock for why (MySQL's 64-character identifier limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_required_skills', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity', 'opportunity_required_skills_opportunity_idx');
            $table->string('skill_name', 100);
            $table->timestamps();

            $table->unique(['opportunity_type', 'opportunity_id', 'skill_name'], 'opportunity_required_skills_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_required_skills');
    }
};
