<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company self-registration: approval status, where the record came from,
 * the profile a recruiter fills in, and who reviewed it. Purely additive -
 * `status` defaults to APPROVED and `source` to ADMIN so every company the
 * placement office already created keeps working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('status', 20)->default('APPROVED')->after('is_active');
            $table->string('source', 20)->default('ADMIN')->after('status');
            $table->string('headquarters', 150)->nullable()->after('description');
            $table->string('company_size', 20)->nullable()->after('headquarters');
            $table->string('registration_number', 64)->nullable()->after('company_size');
            $table->string('contact_name', 150)->nullable()->after('registration_number');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone', 20)->nullable()->after('contact_email');
            $table->foreignId('reviewed_by')->nullable()->after('contact_phone')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status', 'source', 'headquarters', 'company_size', 'registration_number',
                'contact_name', 'contact_email', 'contact_phone', 'reviewed_by', 'reviewed_at', 'review_note',
            ]);
        });
    }
};
