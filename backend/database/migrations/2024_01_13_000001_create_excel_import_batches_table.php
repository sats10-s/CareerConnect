<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel Sync Center - one row per uploaded Excel file, from the moment
 * it lands on disk through parse/validate/preview/confirm/rollback.
 * This is the parent record for excel_import_rows (per-row preview/
 * outcome cache) and excel_import_changes (the field-level audit trail
 * the module spec's section 12 requires) - see those migrations' own
 * docblocks.
 *
 * Field list is exactly the module spec's section 11 list, plus two
 * reasoned additions (documented inline below) needed to make the
 * batch actually retrievable/attributable/debuggable - the same kind
 * of necessary-addition-beyond-the-literal-spec this codebase has
 * precedent for (e.g. internship_completions.status).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('excel_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('import_type', 40);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('stored_filename');

            // Reasoned addition: the spec's literal field list has
            // `stored_filename` but a file also needs a *path* to be
            // retrievable via Storage::disk() - the leaf filename alone
            // isn't enough once files are namespaced under
            // excel-imports/{batch}/... (see ExcelImportService).
            $table->string('storage_path');

            $table->string('status', 30)->default('UPLOADED');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);

            // Reasoned addition: who confirmed/rolled back a batch is
            // as much a part of "audit all imports" (module spec
            // section 33) as *when* - confirmed_at/rolled_back_at alone
            // can't answer "who did this".
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rolled_back_at')->nullable();

            // Reasoned addition: FAILED is a listed batch status (spec
            // section 11) but nothing in the spec's literal field list
            // holds WHY a batch failed - without this an admin (or a
            // test) has no way to see the critical-failure reason a
            // rolled-back transaction produced (spec section 10, "If
            // critical failure occurs: ROLL BACK the transaction").
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['import_type', 'status'], 'excel_import_batch_type_status_idx');
            $table->index('uploaded_by', 'excel_import_batch_uploaded_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excel_import_batches');
    }
};
