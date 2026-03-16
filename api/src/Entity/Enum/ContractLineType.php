<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ContractLineType: string
{
    case FORFAIT_MAINTENANCE = 'FORFAIT_MAINTENANCE';
    case IMPRIMANTE = 'IMPRIMANTE';
    case INTERVENTION = 'INTERVENTION';
    case AUTRE = 'AUTRE';
}
