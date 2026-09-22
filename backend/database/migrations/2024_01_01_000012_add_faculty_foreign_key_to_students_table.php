<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * students.faculty_mentor_id -> faculty.id.
 *
 * Added in its own migration because `faculty` rows reference
 * `users`/`departments` and are created after `students`, so the FK
 * constraint can only be attached once the `faculty` table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreign('faculty_mentor_id')
                ->references('id')->on('faculty')
                ->nullOnDelete();
            $table->index('faculty_mentor_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['faculty_mentor_id']);
            $table->dropIndex(['faculty_mentor_id']);
        });
    }
};
