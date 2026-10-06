<?php

namespace App\Enums;

enum CategorizationStatus: string
{
    case Uncategorized = 'uncategorized';

    /** Proposed by AI; needs a human to approve. */
    case Suggested = 'suggested';

    /** Set by a rule or confirmed by a person. */
    case Approved = 'approved';
}
