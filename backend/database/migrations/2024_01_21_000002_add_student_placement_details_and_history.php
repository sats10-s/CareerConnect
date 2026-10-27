<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('placement_company', 255)->nullable();
            $table->string('placement_job_role', 255)->nullable();
            $table->decimal('placement_package', 10, 2)->nullable();
            $table->date('placement_date')->nullable();
            $table->foreignId('placement_status_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('placement_status_updated_at')->nullable();
        });

        // Compatibility with the earlier eligibility feature's spelling.
        DB::table('students')->where('placement_status', 'UNPLACED')->update(['placement_status' => 'NOT_PLACED']);

        Schema::create('student_placement_status_history', function (Blueprint $table) {
            $table->id();
            // Preserve the audit trail when a student record is removed.
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->json('old_details')->nullable();
            $table->json('new_details')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['student_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_placement_status_history');
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('placement_status_updated_by');
            $table->dropColumn([
                'placement_company', 'placement_job_role', 'placement_package', 'placement_date',
                'placement_status_updated_at',
            ]);
        });
        DB::table('students')->where('placement_status', 'NOT_PLACED')->update(['placement_status' => 'UNPLACED']);
    }
};
