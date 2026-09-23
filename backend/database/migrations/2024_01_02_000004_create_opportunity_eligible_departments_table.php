<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which departments a JobPosting/Internship is open to - a normalized
 * many-to-many (opportunity <-> department) rather than an opaque JSON
 * column, so it stays queryable and FK-valid. An opportunity with no
 * rows here is interpreted as "open to all departments" (no restriction),
 * consistent with how the other eligible_* tables below are read.
 *
 * `morphs('opportunity')` is given an explicit index name - see
 * 2024_01_02_000003_create_opportunity_eligibility_criteria_table's
 * docblock for why (MySQL's 64-character identifier limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_eligible_departments', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity', 'opportunity_eligible_departments_opportunity_idx');
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['opportunity_type', 'opportunity_id', 'department_id'], 'opportunity_eligible_departments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_eligible_departments');
    }
};
