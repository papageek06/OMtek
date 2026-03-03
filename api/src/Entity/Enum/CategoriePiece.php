<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CategoriePiece: string
{
    case TONER = 'TONER';
    case TAMBOUR = 'TAMBOUR';
    case PCDU = 'PCDU';
    case FUSER = 'FUSER';
    case BAC_RECUP = 'BAC_RECUP';
    case COURROIE = 'COURROIE';
    case ROULEAU = 'ROULEAU';
    case KIT_MAINTENANCE = 'KIT_MAINTENANCE';
    case AUTRE = 'AUTRE';

    public function getLibelle(): string
    {
        return match ($this) {
            self::TONER => 'Toner',
            self::TAMBOUR => 'Tambour',
            self::PCDU => 'PCDU',
            self::FUSER => 'Unité de fusion',
            self::BAC_RECUP => 'Bac récupération',
            self::COURROIE => 'Courroie',
            self::ROULEAU => 'Rouleau',
            self::KIT_MAINTENANCE => 'Kit maintenance',
            self::AUTRE => 'Autre',
        };
    }

    public static function tryFromString(string $value): ?self
    {
        $v = strtoupper(trim($value));
        return self::tryFrom($v) ?? match ($v) {
            'DRUM' => self::TAMBOUR,
            'KIT_ENTRETIEN' => self::KIT_MAINTENANCE,
            default => null,
        };
    }
}
