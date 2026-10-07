<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The annual package (in rupees, same unit as ppo_records.package) a
 * SELECTED application was offered. Job postings only carry a free-text
 * salary_range, which cannot be averaged, so the Package Report reads
 * this column instead. Nullable: an offer whose package the placement
 * office hasn't entered yet is "not entered", never 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->decimal('offer_package', 12, 2)->nullable()->after('selected_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('offer_package');
        });
    }
};
