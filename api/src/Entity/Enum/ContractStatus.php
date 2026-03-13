<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ContractStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case CLOSED = 'CLOSED';
}

