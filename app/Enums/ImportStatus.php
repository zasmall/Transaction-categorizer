<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Parsing = 'parsing';
    case Normalizing = 'normalizing';
    case Persisting = 'persisting';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }
}
