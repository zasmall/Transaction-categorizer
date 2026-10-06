<?php

namespace App\Enums;

enum RuleMatchField: string
{
    case Payee = 'payee';
    case Description = 'description';
    case Memo = 'memo';

    public function label(): string
    {
        return match ($this) {
            self::Payee => 'Payee',
            self::Description => 'Bank description',
            self::Memo => 'Memo',
        };
    }
}
