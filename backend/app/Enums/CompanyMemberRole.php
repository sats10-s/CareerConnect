<?php

namespace App\Enums;

/**
 * A company login's role within its company. The OWNER registered the
 * company and manages its team; RECRUITER logins are added by the owner and
 * can do everything in the portal except manage the team.
 */
enum CompanyMemberRole: string
{
    case OWNER = 'OWNER';
    case RECRUITER = 'RECRUITER';
}
