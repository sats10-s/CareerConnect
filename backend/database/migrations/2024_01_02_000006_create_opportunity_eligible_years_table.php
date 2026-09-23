<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which academic years (1-4, matching students.year - see
 * 2024_01_01_000016_add_year_cgpa_backlogs_to_students_table) a
 * JobPosting/Internship is open to. No FK target (year is a plain
 * 1-4 integer on the students table, not a lookup table), so this is
 * just an unsigned tinyint - range-validated at the Form Request layer
 * (between:1,4), same as students.year itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_eligible_years', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity');
            $table->unsignedTinyInteger('year');
            $table->timestamps();

            $table->unique(['opportunity_type', 'opportunity_id', 'year'], 'opportunity_eligible_years_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_eligible_years');
    }
};
