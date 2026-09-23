<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * The admin-side detail payload for GET /api/admin/attendance/{attendance}
 * and PATCH /api/admin/attendance/{attendance}. A thin subclass of
 * AttendanceResource rather than a second, duplicated field list: the
 * base class already computes every field an admin needs (including
 * history/marked_by/timestamps, gated behind its own viewerIsAdmin()
 * runtime check) - this subclass exists so the admin-only controller
 * does not depend on that runtime role check to get the fuller shape
 * (it is used in a context that IS always admin, by route middleware,
 * so the admin-only fields are simply always included here rather than
 * re-derived from the request).
 *
 * @mixin \App\Models\Attendance
 */
class AdminAttendanceResource extends AttendanceResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        return array_merge($data, [
            'history' => $this->whenLoaded('history', fn () => AttendanceHistoryResource::collection($this->history)),
            'marked_by' => $this->whenLoaded('markedBy', fn () => $this->markedBy ? [
                'id' => $this->markedBy->id,
                'name' => $this->markedBy->name,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ]);
    }
}
