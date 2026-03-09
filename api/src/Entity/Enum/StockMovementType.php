<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum StockMovementType: string
{
    case ENTREE = 'ENTREE';
    case SORTIE = 'SORTIE';
    case AJUSTEMENT = 'AJUSTEMENT';
    case TRANSFERT = 'TRANSFERT';
}
