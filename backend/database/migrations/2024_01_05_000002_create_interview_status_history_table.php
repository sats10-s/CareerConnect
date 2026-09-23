<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every interview status change - mirrors
 * application_status_history exactly (see
 * 2024_01_04_000002_create_application_status_history_table's
 * docblock for the shared reasoning: no `updated_at`, changed_by
 * nullOnDelete). App\Services\InterviewService is the only writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_id')->constrained('interviews')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['interview_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_status_history');
    }
};
