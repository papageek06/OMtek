<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum NaturePiece: string
{
    case CONSUMABLE = 'CONSUMABLE';
    case SPARE_PART = 'SPARE_PART';

    public function getLibelle(): string
    {
        return match ($this) {
            self::CONSUMABLE => 'Consommable',
            self::SPARE_PART => 'Pièce détachée',
        };
    }
}
