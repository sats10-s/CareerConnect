<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('roll_number', 32)->unique();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->string('phone', 20)->nullable();
            // faculty_mentor_id -> faculty.id is added as a real FK in a
            // later migration, once the `faculty` table exists (see
            // 2024_01_01_000012_add_faculty_mentor_foreign_key...).
            $table->unsignedBigInteger('faculty_mentor_id')->nullable();
            $table->timestamps();

            $table->index('department_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
