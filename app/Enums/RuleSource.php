<?php

namespace App\Enums;

enum RuleSource: string
{
    /** Written by a person. */
    case Manual = 'manual';

    /** Suggested by the app from repeated approvals. */
    case Learned = 'learned';
}
