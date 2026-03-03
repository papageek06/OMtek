<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum TypePiece: string
{
    case TONER = 'toner';
    case BAC_RECUP = 'bac_recup';
    case DRUM = 'drum';
    case KIT_ENTRETIEN = 'kit_entretien';
    case FOURNITURES_CONSOMMABLES = 'Fournitures Consommables';
    case NPU = 'NPU';
    case VENTES_COPIEURS = 'Ventes Copieurs';
    case AUTRE = 'autre';

    public function getLibelle(): string
    {
        return match ($this) {
            self::TONER => 'Toner',
            self::BAC_RECUP => 'Bac récupération',
            self::DRUM => 'Tambour',
            self::KIT_ENTRETIEN => 'Kit d\'entretien',
            self::FOURNITURES_CONSOMMABLES => 'Fournitures Consommables',
            self::NPU => 'NPU',
            self::VENTES_COPIEURS => 'Ventes Copieurs',
            self::AUTRE => 'Autre',
        };
    }
}
