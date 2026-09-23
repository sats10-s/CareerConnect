<?php

namespace App\Enums;

/**
 * Lifecycle of one Pre-Placement Offer (PPO) record - see
 * App\Models\PpoRecord and App\Services\InternshipCompletionService
 * (the only writer of a new status). ALL-CAPS backed enum,
 * canTransitionTo()/isTerminal() pattern mirroring
 * InternshipCompletionStatus/InternshipDocumentStatus exactly.
 *
 * OFFERED -> {ACCEPTED | DECLINED}. Both ACCEPTED and DECLINED are
 * terminal - once a student has responded to an offer, the record
 * becomes immutable (mirrors the "Finalized completion is immutable"
 * rule applied here by the same reasoning: a decided PPO is a fact of
 * record, not something to be silently edited afterwards). A fresh PPO
 * record is always created OFFERED - there is no route to create one
 * directly as ACCEPTED/DECLINED.
 */
enum PpoStatus: string
{
    case OFFERED = 'OFFERED';
    case ACCEPTED = 'ACCEPTED';
    case DECLINED = 'DECLINED';

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
            self::OFFERED => in_array($target, [self::ACCEPTED, self::DECLINED], true),
            self::ACCEPTED, self::DECLINED => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::DECLINED], true);
    }
}
