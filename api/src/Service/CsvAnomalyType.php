<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Types d'anomalies rencontrees pendant l'import CSV BACKUP.
 */
final class CsvAnomalyType
{
    /** Meme serial sur plusieurs clients sans pouvoir determiner la ligne la plus recente (date manquante ou egale). */
    public const SERIAL_MULTI_SITE_STALE_ROW = 'SERIAL_MULTI_SITE_STALE_ROW';
    public const SERIAL_MULTI_SITE_UNKNOWN_PRINTER = 'SERIAL_MULTI_SITE_UNKNOWN_PRINTER';
    /** Meme serial sur plusieurs clients: la ligne au dernier scan le plus recent a ete retenue, les autres ignorees. */
    public const SERIAL_DUPLICATE_IN_IMPORT = 'SERIAL_DUPLICATE_IN_IMPORT';
    /** Imprimante connue remontee sur un autre client: deplacement refuse (date absente ou compteur qui recule). */
    public const SERIAL_SITE_MISMATCH = 'SERIAL_SITE_MISMATCH';
    /** Imprimante deplacee automatiquement (trace, deja resolue). */
    public const SERIAL_SITE_AUTO_MOVED = 'SERIAL_SITE_AUTO_MOVED';
    public const MISSING_SERIAL = 'MISSING_SERIAL';
    public const MISSING_CUSTOMER = 'MISSING_CUSTOMER';
    public const INVALID_ROW = 'INVALID_ROW';
    /** LAST_SCAN_DATE renseignee mais illisible: la ligne est importee sans controle de fraicheur. */
    public const INVALID_LAST_SCAN_DATE = 'INVALID_LAST_SCAN_DATE';
    /** L'import complet a echoue et a ete annule: les lignes sont conservees pour relance. */
    public const IMPORT_FAILED = 'IMPORT_FAILED';

    /** Types pour lesquels l'admin peut rattacher l'imprimante au client indique par la ligne CSV. */
    public const SITE_CONFLICT_TYPES = [
        self::SERIAL_MULTI_SITE_STALE_ROW,
        self::SERIAL_MULTI_SITE_UNKNOWN_PRINTER,
        self::SERIAL_DUPLICATE_IN_IMPORT,
        self::SERIAL_SITE_MISMATCH,
        self::SERIAL_SITE_AUTO_MOVED,
    ];

    private function __construct()
    {
    }
}
