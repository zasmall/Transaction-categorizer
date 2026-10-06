<?php

namespace App\Enums;

enum RuleOperator: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Equals = 'equals';
    case Regex = 'regex';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'contains',
            self::StartsWith => 'starts with',
            self::Equals => 'equals',
            self::Regex => 'matches pattern',
        };
    }
}
