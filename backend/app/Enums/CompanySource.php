<?php

namespace App\Enums;

/** Where a company record came from. */
enum CompanySource: string
{
    case ADMIN = 'ADMIN';
    case SELF_REGISTERED = 'SELF_REGISTERED';
}
