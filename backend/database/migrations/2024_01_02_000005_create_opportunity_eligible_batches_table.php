<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which batches a JobPosting/Internship is open to - see
 * 2024_01_02_000004_create_opportunity_eligible_departments_table for
 * the same "no rows = no restriction" reading and the reasoning for a
 * normalized many-to-many over a JSON column.
 *
 * `morphs('opportunity')` is given an explicit index name - see
 * 2024_01_02_000003_create_opportunity_eligibility_criteria_table's
 * docblock for why (MySQL's 64-character identifier limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_eligible_batches', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity', 'opportunity_eligible_batches_opportunity_idx');
            $table->foreignId('batch_id')->constrained('batches')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['opportunity_type', 'opportunity_id', 'batch_id'], 'opportunity_eligible_batches_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_eligible_batches');
    }
};
