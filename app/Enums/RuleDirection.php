<?php

namespace App\Enums;

enum RuleDirection: string
{
    case Any = 'any';

    /** Money in (positive amounts). */
    case Inflow = 'inflow';

    /** Money out (negative amounts). */
    case Outflow = 'outflow';

    public function allows(int $amountCents): bool
    {
        return match ($this) {
            self::Any => true,
            self::Inflow => $amountCents > 0,
            self::Outflow => $amountCents < 0,
        };
    }
}
