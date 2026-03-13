<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ContractIndexationType: string
{
    case MANUAL_COEFFICIENT = 'MANUAL_COEFFICIENT';
    case FIXED_PERCENTAGE = 'FIXED_PERCENTAGE';
    case EXTERNAL_INDEX = 'EXTERNAL_INDEX';
}

