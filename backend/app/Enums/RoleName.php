<?php

namespace App\Enums;

/**
 * The four roles supported by CareerConnect.
 *
 * The canonical role record lives in the `roles` table (see the
 * RoleSeeder), this enum just gives the application strongly-typed,
 * autocompletable access to the same fixed set of role names so we
 * never scatter magic strings through controllers/middleware.
 */
enum RoleName: string
{
    case STUDENT = 'STUDENT';
    case FACULTY = 'FACULTY';
    case PLACEMENT_ADMIN = 'PLACEMENT_ADMIN';
    case SUPER_ADMIN = 'SUPER_ADMIN';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::STUDENT => 'Student',
            self::FACULTY => 'Faculty',
            self::PLACEMENT_ADMIN => 'Placement Admin',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }

    /**
     * The frontend route a user with this role should land on after login.
     */
    public function dashboardPath(): string
    {
        return match ($this) {
            self::STUDENT => '/student/dashboard',
            self::FACULTY => '/faculty/dashboard',
            self::PLACEMENT_ADMIN => '/admin/dashboard',
            self::SUPER_ADMIN => '/super-admin/dashboard',
        };
    }
}
