<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum BillingLineType: string
{
    case FORFAIT_MAINTENANCE = 'FORFAIT_MAINTENANCE';
    case COMPTEUR_NOIR = 'COMPTEUR_NOIR';
    case COMPTEUR_COULEUR = 'COMPTEUR_COULEUR';
    case INTERVENTION = 'INTERVENTION';
    case AJUSTEMENT = 'AJUSTEMENT';
}

