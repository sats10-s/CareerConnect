<?php

namespace App\Enums;

/**
 * Placeholder lifecycle for the `applications` table. Full workflow
 * (interviews, offers, etc.) is intentionally out of scope for this
 * foundation and will be built out in a later prompt.
 */
enum ApplicationStatus: string
{
    case APPLIED = 'applied';
    case SHORTLISTED = 'shortlisted';
    case INTERVIEW = 'interview';
    case SELECTED = 'selected';
    case REJECTED = 'rejected';
}
