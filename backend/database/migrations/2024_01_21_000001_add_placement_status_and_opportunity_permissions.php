<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Existing records are UNKNOWN until the placement office verifies them.
            // UNKNOWN is restricted by the eligibility service (fail closed).
            $table->string('placement_status', 16)->default('UNKNOWN')->index();
        });

        Schema::create('opportunity_placement_policies', function (Blueprint $table) {
            $table->id();
            $table->morphs('opportunity', 'opp_place_policy_morph_idx');
            $table->boolean('allow_placed_students')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['opportunity_type', 'opportunity_id'], 'opp_placement_policy_unique');
        });

        Schema::create('student_opportunity_placement_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->morphs('opportunity', 'student_opp_exception_morph_idx');
            $table->text('grant_reason');
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->text('revocation_reason')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'opportunity_type', 'opportunity_id', 'revoked_at'], 'student_opp_exception_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_opportunity_placement_exceptions');
        Schema::dropIfExists('opportunity_placement_policies');
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex(['placement_status']);
            $table->dropColumn('placement_status');
        });
    }
};
