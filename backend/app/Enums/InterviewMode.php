<?php

namespace App\Enums;

/**
 * How an interview round is conducted. Mode-specific field
 * requirements (ONLINE needs meeting_url, OFFLINE needs venue, HYBRID
 * needs at least one of the two) are enforced in the Form Requests -
 * see App\Http\Requests\Concerns\ValidatesInterviewMode - not here;
 * this enum is deliberately just the closed set of valid values
 * (Rule::enum(self::class) is what rejects any other string).
 */
enum InterviewMode: string
{
    case ONLINE = 'ONLINE';
    case OFFLINE = 'OFFLINE';
    case HYBRID = 'HYBRID';
}
