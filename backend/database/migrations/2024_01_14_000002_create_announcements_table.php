<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications & Announcements module - admin/super-admin-authored
 * university/company/career announcements. See App\Models\Announcement
 * and App\Services\AnnouncementService (the only writer).
 *
 * `notified_at` is a reasoned addition beyond the module spec's literal
 * field list (id, title, body, category, audience, status, publish_at,
 * expires_at, created_by, updated_by, timestamps) - the same kind of
 * necessary-addition-beyond-the-literal-spec this codebase already has
 * precedent for (e.g. excel_import_batches.storage_path/error_message -
 * see that migration's own docblock). Without it there is no way to
 * satisfy the module spec's own section 8 requirement ("do not create
 * duplicate notifications every time the announcement is fetched")
 * other than re-querying the notifications table for a matching
 * announcement_id on every single publish() call; a single nullable
 * timestamp, set once by AnnouncementService::notifyAudience(), is the
 * simpler and cheaper guard.
 *
 * Every index this module's own spec explicitly calls out (status,
 * audience, publish_at, expires_at) is added here with an explicit,
 * short name, matching this codebase's own precedent - see
 * 2024_01_13_000001_create_excel_import_batches_table's ..._idx names.
 * This table's own default FK constraint names
 * (announcements_created_by_foreign / announcements_updated_by_foreign)
 * are themselves comfortably under MySQL's 64-character identifier
 * limit, so they are left as Laravel's defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('category', 20);
            $table->string('audience', 20);
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index('status', 'announcements_status_idx');
            $table->index('audience', 'announcements_audience_idx');
            $table->index('publish_at', 'announcements_publish_at_idx');
            $table->index('expires_at', 'announcements_expires_at_idx');
            $table->index(['status', 'audience', 'publish_at'], 'announcements_active_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
