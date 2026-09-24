<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single representation used for every notification endpoint
 * (list and single) across all three role namespaces
 * (Api\Student\NotificationController, Api\Faculty\NotificationController,
 * Api\Admin\NotificationController) - matches the module spec's
 * exact response shape (section 10). `data` always resolves to an
 * array (never null) - App\Models\Notification casts the `data`
 * column as 'array' and App\Services\NotificationService::create()
 * always writes at least an empty array, never a raw null.
 *
 * @mixin \App\Models\Notification
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data ?? [],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
