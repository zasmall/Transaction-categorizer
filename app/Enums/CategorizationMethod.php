<?php

namespace App\Enums;

enum CategorizationMethod: string
{
    case Rule = 'rule';
    case Ai = 'ai';
    case Manual = 'manual';
}
