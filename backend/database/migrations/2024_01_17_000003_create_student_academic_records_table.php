<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CGPA/backlogs history. One row per change (and one for the first value a
 * student is imported with): the new values, the values they replaced, who
 * changed them, why, and - for file imports - which import did it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('cgpa', 4, 2)->nullable();
            $table->unsignedTinyInteger('backlogs')->nullable();
            $table->decimal('previous_cgpa', 4, 2)->nullable();
            $table->unsignedTinyInteger('previous_backlogs')->nullable();
            $table->string('semester_label', 40)->nullable();
            $table->string('source', 10);
            $table->foreignId('import_id')->nullable()->constrained('excel_import_batches')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_academic_records');
    }
};
