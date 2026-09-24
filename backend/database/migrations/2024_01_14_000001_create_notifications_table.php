<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications & Announcements module - one row per persistent
 * in-app notification for one recipient. See App\Models\Notification
 * and App\Services\NotificationService (the only writer). Deliberately
 * a single flat table for every notification type (App\Enums\NotificationType)
 * rather than one table per domain event - the same {type, title,
 * message, data} shape already covers every event this module
 * integrates with (App\Listeners\*) and every admin-authored
 * announcement fan-out (App\Services\AnnouncementService::notifyAudience()),
 * so a second table would only duplicate this one's columns.
 *
 * Every index this module's own spec explicitly calls out is added
 * here with an explicit, short name (this table's own default FK
 * constraint name, notifications_recipient_user_id_foreign, is itself
 * comfortably under MySQL's 64-character identifier limit, so it is
 * left as Laravel's default; only the plain/composite indexes below
 * are given explicit short names, matching this codebase's own
 * precedent - see 2024_01_13_000001_create_excel_import_batches_table's
 * ..._idx names).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('recipient_user_id', 'notifications_recipient_idx');
            $table->index('read_at', 'notifications_read_at_idx');
            $table->index('created_at', 'notifications_created_at_idx');
            $table->index(['recipient_user_id', 'read_at'], 'notifications_recipient_read_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
