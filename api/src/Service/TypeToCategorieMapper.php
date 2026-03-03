<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\CategoriePiece;

/**
 * Mappe l'ancien champ "type" vers la nouvelle "categorie" pour rétrocompatibilité.
 */
final class TypeToCategorieMapper
{
    private const TYPE_TO_CATEGORIE = [
        'toner' => CategoriePiece::TONER,
        'bac_recup' => CategoriePiece::BAC_RECUP,
        'bac recup' => CategoriePiece::BAC_RECUP,
        'drum' => CategoriePiece::TAMBOUR,
        'tambour' => CategoriePiece::TAMBOUR,
        'kit_entretien' => CategoriePiece::KIT_MAINTENANCE,
        'kit entretien' => CategoriePiece::KIT_MAINTENANCE,
        'Fournitures Consommables' => CategoriePiece::TONER, // fournitures -> consommable, proche toner
        'NPU' => CategoriePiece::AUTRE,
        'Ventes Copieurs' => CategoriePiece::AUTRE,
        'autre' => CategoriePiece::AUTRE,
    ];

    public static function typeToCategorie(?string $type): CategoriePiece
    {
        if ($type === null || $type === '') {
            return CategoriePiece::AUTRE;
        }
        $key = strtolower(trim($type));
        return self::TYPE_TO_CATEGORIE[$key] ?? CategoriePiece::AUTRE;
    }
}
