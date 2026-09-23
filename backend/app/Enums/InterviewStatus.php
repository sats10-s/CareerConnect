<?php

namespace App\Enums;

/**
 * Lifecycle for one interview round - see App\Models\Interview and
 * App\Services\InterviewService (the only place that writes a new
 * status). RESCHEDULED is not terminal: a rescheduled interview can
 * still be completed, cancelled, or rescheduled again - the SAME
 * interview row moves through this graph over its lifetime rather
 * than each reschedule spawning a new row (see the interviews
 * migration's docblock for the full reasoning).
 */
enum InterviewStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case RESCHEDULED = 'RESCHEDULED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::SCHEDULED, self::RESCHEDULED => in_array($target, [self::COMPLETED, self::CANCELLED, self::RESCHEDULED], true),
            self::COMPLETED, self::CANCELLED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED], true);
    }
}
