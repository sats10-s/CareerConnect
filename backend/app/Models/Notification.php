<?php

namespace App\Models;

use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One persistent in-app notification for one recipient - see the
 * notifications migration's docblock and App\Services\NotificationService
 * (the only writer). `recipient_user_id` is always derived server-side
 * from an already-authenticated/resolved User (never a client-supplied
 * id) - see every App\Listeners\* class and
 * App\Services\AnnouncementService::recipientsFor(), which is what
 * makes App\Policies\NotificationPolicy::view()'s ownership check
 * (`$notification->recipient_user_id === $user->id`) a real IDOR
 * guard rather than a formality.
 */
class Notification extends Model
{
    protected $fillable = [
        'recipient_user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
