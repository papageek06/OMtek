<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum VariantPiece: string
{
    case BLACK = 'BLACK';
    case CYAN = 'CYAN';
    case MAGENTA = 'MAGENTA';
    case YELLOW = 'YELLOW';
    case UNIT = 'UNIT';
    case KIT = 'KIT';
    case NONE = 'NONE';

    public function getLibelle(): string
    {
        return match ($this) {
            self::BLACK => 'Noir',
            self::CYAN => 'Cyan',
            self::MAGENTA => 'Magenta',
            self::YELLOW => 'Jaune',
            self::UNIT => 'Unité',
            self::KIT => 'Kit',
            self::NONE => '-',
        };
    }
}
