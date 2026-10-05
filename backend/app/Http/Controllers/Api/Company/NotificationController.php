<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Api\Admin\NotificationController as BaseNotificationController;

/**
 * /api/company/notifications - same user-scoped behaviour as every other
 * role's notification endpoints (recipient is always $request->user()).
 * Open while the company is pending/rejected/suspended, so the owner can
 * read the review decision.
 */
class NotificationController extends BaseNotificationController {}
