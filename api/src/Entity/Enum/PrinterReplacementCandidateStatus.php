<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum PrinterReplacementCandidateStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
}
