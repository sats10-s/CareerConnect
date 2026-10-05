<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student Data import: the college's own columns (Section, Gender, Year of
 * Joining, 10th, Inter/Diploma) plus what is derived from the JNTU No
 * (entry type, program). personal_email/linkedin_url/portfolio_url already
 * exist (2024_01_01_000017); `address` is the one new student-owned field.
 *
 * `backlogs` loses its default of 0: a blank "No. of Backlogs" cell means
 * "not reported", which must never read as "no backlogs". Existing rows
 * keep whatever value they already have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('section', 10)->nullable()->after('year');
            $table->string('gender', 10)->nullable()->after('section');
            $table->unsignedSmallInteger('year_of_joining')->nullable()->after('gender');
            $table->string('entry_type', 10)->nullable()->after('year_of_joining');
            $table->string('program', 10)->nullable()->after('entry_type');
            $table->decimal('ssc_score', 5, 2)->nullable()->after('backlogs');
            $table->string('ssc_scale', 10)->nullable()->after('ssc_score');
            $table->decimal('inter_score', 5, 2)->nullable()->after('ssc_scale');
            $table->string('inter_type', 10)->nullable()->after('inter_score');
            $table->timestamp('academics_updated_at')->nullable()->after('inter_type');
            $table->string('address', 500)->nullable()->after('portfolio_url');

            $table->index('section');
            $table->index('year_of_joining');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedTinyInteger('backlogs')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropIndex(['year_of_joining']);
            $table->dropColumn([
                'section', 'gender', 'year_of_joining', 'entry_type', 'program',
                'ssc_score', 'ssc_scale', 'inter_score', 'inter_type', 'academics_updated_at', 'address',
            ]);
        });
    }
};
