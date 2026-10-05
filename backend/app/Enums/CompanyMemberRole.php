<?php

namespace App\Enums;

/**
 * A company login's role within its company. v1 has exactly one login per
 * company, the OWNER who registered it; RECRUITER seats are a later step.
 */
enum CompanyMemberRole: string
{
    case OWNER = 'OWNER';
}
