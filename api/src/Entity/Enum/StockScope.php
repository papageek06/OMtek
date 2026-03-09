<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum StockScope: string
{
    case TECH_VISIBLE = 'TECH_VISIBLE';
    case ADMIN_ONLY = 'ADMIN_ONLY';

    public function getLibelle(): string
    {
        return match ($this) {
            self::TECH_VISIBLE => 'Visible technicien',
            self::ADMIN_ONLY => 'Reserve admin',
        };
    }
}
