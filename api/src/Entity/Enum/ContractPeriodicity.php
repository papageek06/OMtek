<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ContractPeriodicity: string
{
    case MONTHLY = 'MONTHLY';
    case QUARTERLY = 'QUARTERLY';
    case SEMIANNUAL = 'SEMIANNUAL';
    case YEARLY = 'YEARLY';
}
