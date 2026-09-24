<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;

/**
 * Object-level authorization for the Notifications module. A
 * notification has exactly one legitimate viewer - its recipient -
 * regardless of that recipient's role, and regardless of the ROLE of
 * whoever is asking: unlike every admin-scoped policy in this codebase
 * (ApplicationPolicy, ExcelImportBatchPolicy, ...), there is
 * deliberately NO isAdmin()/"admin can see everything" bypass here.
 * The module spec is explicit that Super Admin must not accidentally
 * bypass ownership for ordinary user notifications - Api\Admin\NotificationController
 * only ever lets an admin mark/read THEIR OWN notifications (their own
 * ANNOUNCEMENT/SYSTEM ones), never another user's, exactly like
 * Api\Student\NotificationController / Api\Faculty\NotificationController.
 * This single ownership check, reused identically by all three role
 * namespaces, is what makes that guarantee structural rather than a
 * per-controller convention that could quietly drift.
 */
class NotificationPolicy
{
    public function view(User $user, Notification $notification): bool
    {
        return $notification->recipient_user_id === $user->id;
    }

    public function markRead(User $user, Notification $notification): bool
    {
        return $notification->recipient_user_id === $user->id;
    }
}
