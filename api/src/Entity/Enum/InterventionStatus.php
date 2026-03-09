<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionStatus: string
{
    case A_FAIRE = 'A_FAIRE';
    case EN_COURS = 'EN_COURS';
    case TERMINEE = 'TERMINEE';
    case ANNULEE = 'ANNULEE';
}
