<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionType: string
{
    case LIVRAISON_TONER = 'LIVRAISON_TONER';
    case DEPANNAGE = 'DEPANNAGE';
    case TELEMAINTENANCE = 'TELEMAINTENANCE';
    case AUTRE = 'AUTRE';
}
