<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholder table only - the real application workflow (interview
 * scheduling, offer letters, status history, etc.) is out of scope
 * for this foundation and will be built in a later prompt.
 *
 * `applicable` is a polymorphic relation to either a JobPosting or
 * an Internship so one table can back applications to both without
 * duplicating columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->morphs('applicable'); // applicable_type, applicable_id
            $table->string('status', 20)->default('applied');
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            $table->unique(['student_id', 'applicable_type', 'applicable_id'], 'applications_unique_per_student');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
