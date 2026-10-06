<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    case Pending = 'pending';
    case Normalized = 'normalized';
    case Imported = 'imported';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
