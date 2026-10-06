<?php

namespace App\Enums;

/**
 * Approval state of a company. Companies the placement office creates are
 * APPROVED immediately; self-registered ones start PENDING until a Super
 * Admin reviews them. Only APPROVED companies' postings are shown to
 * students, and only APPROVED company logins get past the `company.approved`
 * middleware.
 */
enum CompanyStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case SUSPENDED = 'SUSPENDED';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::SUSPENDED => 'Suspended',
        };
    }
}
