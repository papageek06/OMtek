<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum StockMovementReason: string
{
    case INVENTAIRE = 'INVENTAIRE';
    case LIVRAISON = 'LIVRAISON';
    case DEPANNAGE = 'DEPANNAGE';
    case REAPPRO = 'REAPPRO';
    case CORRECTION = 'CORRECTION';
    case TRANSFERT_SITE = 'TRANSFERT_SITE';
    case TRANSFERT_RESERVE = 'TRANSFERT_RESERVE';
}
