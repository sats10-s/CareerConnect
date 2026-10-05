<?php

namespace App\Enums;

/**
 * The roles supported by CareerConnect. COMPANY is the external
 * recruiter login created by company self-registration.
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
    case COMPANY = 'COMPANY';

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
            self::COMPANY => 'Company Recruiter',
        };
    }

    /**
     * The frontend route a user with this role should land on after login.
     */
    /**
     * What each role can do, for Super Admin > Roles. Roles are fixed in
     * code (route middleware + policies), so this text describes those rules
     * rather than configuring them.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::STUDENT => [
                'Browse published jobs, internships and placement drives',
                'Apply, track applications and see interview schedules',
                'Manage their own profile and resume',
                'Submit weekly reports and internship documents for their own internship',
            ],
            self::FACULTY => [
                'See only the students assigned to them as internship coordinator',
                'Mark and correct attendance for those students',
                'Review weekly reports and documents, record completion and PPOs',
                'View their own workload report',
            ],
            self::PLACEMENT_ADMIN => [
                'Manage jobs, internships, placement drives and interviews',
                'Review every application and change its status',
                'Assign faculty coordinators to selected interns',
                'Students directory, companies, Excel import/export and institution reports',
            ],
            self::SUPER_ADMIN => [
                'Everything a Placement Admin can do',
                'Create and manage staff accounts, activate or deactivate any user',
                'Manage departments, batches and institution settings',
                'Read the audit log and security overview',
                'Approve, reject, suspend and reactivate self-registered companies',
            ],
            self::COMPANY => [
                'Register their company and keep its profile up to date',
                'Wait for Super Admin approval before anything else is unlocked',
                'Create job and internship postings; the placement office publishes them',
                'Review applicants to their own postings and schedule interviews',
                'Never sees student phone numbers, email addresses, home address or resume files',
                'The owner adds and deactivates recruiter logins for the company',
            ],
        };
    }

    public function dashboardPath(): string
    {
        return match ($this) {
            self::STUDENT => '/student/dashboard',
            self::FACULTY => '/faculty/dashboard',
            self::PLACEMENT_ADMIN => '/admin/dashboard',
            self::SUPER_ADMIN => '/super-admin/dashboard',
            self::COMPANY => '/company/dashboard',
        };
    }
}
