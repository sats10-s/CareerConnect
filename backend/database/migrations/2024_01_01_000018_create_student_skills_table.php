<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('skill_name', 100);
            $table->timestamps();

            $table->index('student_id');
            // A student can list a given skill once - prevents duplicate
            // "React, React, React" entries from repeated add clicks.
            $table->unique(['student_id', 'skill_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_skills');
    }
};
