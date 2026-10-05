<?php

namespace App\Enums;

/**
 * Who an announcement is intended for - see App\Models\Announcement
 * and App\Services\AnnouncementService (audience-scoped visibility
 * queries and notification fan-out both key off this value). ALL-CAPS
 * backed enum, matching this schema's existing convention. PLACEMENT_ADMIN
 * here means "both administrative roles" (PLACEMENT_ADMIN and
 * SUPER_ADMIN) - mirroring the role:PLACEMENT_ADMIN,SUPER_ADMIN pairing
 * every admin-only route in routes/api.php already uses; this schema
 * has no route, policy, or gate anywhere that treats those two roles
 * differently, so introducing a distinct audience value for
 * SUPER_ADMIN alone would be inventing a distinction nothing else in
 * this codebase makes.
 */
enum AnnouncementAudience: string
{
    case ALL = 'ALL';
    case STUDENTS = 'STUDENTS';
    case FACULTY = 'FACULTY';
    case PLACEMENT_ADMIN = 'PLACEMENT_ADMIN';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $audience) => $audience->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::ALL => 'All',
            self::STUDENTS => 'Students',
            self::FACULTY => 'Faculty',
            self::PLACEMENT_ADMIN => 'Placement Admin',
        };
    }

    /**
     * The RoleName values this audience resolves to for notification
     * fan-out (App\Services\AnnouncementService::recipientsFor()) and
     * visibility scoping (Announcement::scopeVisibleTo()). ALL is every
     * institution role, not "every role except admins" - an
     * announcement addressed to everyone genuinely means everyone in the
     * institution. External COMPANY recruiter logins are deliberately not
     * part of it: institution announcements are internal.
     *
     * @return array<int, string>
     */
    public function roleNames(): array
    {
        return match ($this) {
            self::ALL => array_values(array_diff(RoleName::values(), [RoleName::COMPANY->value])),
            self::STUDENTS => [RoleName::STUDENT->value],
            self::FACULTY => [RoleName::FACULTY->value],
            self::PLACEMENT_ADMIN => [RoleName::PLACEMENT_ADMIN->value, RoleName::SUPER_ADMIN->value],
        };
    }
}
