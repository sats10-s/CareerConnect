<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student Data imports (STUDENTS, ACADEMIC_UPDATE) reuse the Excel Sync
 * Center's batch/row/change tables and add:
 *  - batches.summary: per-sheet counts, New/Updated/Unchanged/Warning/Error
 *    counts, unknown departments.
 *  - batches.progress_total/progress_done: the queued confirm job's progress.
 *  - batches.secret: one-time passwords for students without a valid mobile,
 *    encrypted, shown to the Super Admin once and then wiped.
 *  - rows.category: new | updated | unchanged | error - the preview tabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('excel_import_batches', function (Blueprint $table) {
            $table->json('summary')->nullable()->after('error_rows');
            $table->unsignedInteger('progress_total')->default(0)->after('summary');
            $table->unsignedInteger('progress_done')->default(0)->after('progress_total');
            $table->text('secret')->nullable()->after('progress_done');
        });

        Schema::table('excel_import_rows', function (Blueprint $table) {
            $table->string('category', 12)->nullable()->after('outcome');
            $table->index(['import_batch_id', 'category'], 'excel_import_row_batch_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('excel_import_rows', function (Blueprint $table) {
            $table->dropIndex('excel_import_row_batch_category_idx');
            $table->dropColumn('category');
        });

        Schema::table('excel_import_batches', function (Blueprint $table) {
            $table->dropColumn(['summary', 'progress_total', 'progress_done', 'secret']);
        });
    }
};
