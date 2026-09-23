<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `phone` already exists on `students` (see the original
     * create_students_table migration) - reused as-is, not duplicated.
     * This migration only adds the columns the Student Profile module
     * actually introduces: a second (personal) email, the three
     * professional links, and resume metadata.
     *
     * The resume FILE itself never lives in the database - these
     * columns only point at it (see App\Http\Controllers\Api\Student\
     * ResumeController). It's stored on the default `local` filesystem
     * disk (storage/app/private/..., see config/filesystems.php), which
     * Laravel does not expose publicly and has no symlink into public/ -
     * unlike the `public` disk. `resume_path` is a random on-disk
     * filename, never the student's original filename.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('personal_email', 190)->nullable()->after('phone');
            $table->string('linkedin_url', 255)->nullable()->after('personal_email');
            $table->string('github_url', 255)->nullable()->after('linkedin_url');
            $table->string('portfolio_url', 255)->nullable()->after('github_url');

            $table->string('resume_path', 255)->nullable()->after('portfolio_url');
            $table->string('resume_original_name', 255)->nullable()->after('resume_path');
            $table->unsignedInteger('resume_size')->nullable()->after('resume_original_name');
            $table->timestamp('resume_uploaded_at')->nullable()->after('resume_size');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'personal_email',
                'linkedin_url',
                'github_url',
                'portfolio_url',
                'resume_path',
                'resume_original_name',
                'resume_size',
                'resume_uploaded_at',
            ]);
        });
    }
};
