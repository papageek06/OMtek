<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum PrinterAssignmentStatus: string
{
    case EN_SERVICE = 'EN_SERVICE';
    case RETIREE = 'RETIREE';
    case RETOUR_ATELIER = 'RETOUR_ATELIER';
    case RECONDITIONNEE = 'RECONDITIONNEE';
    case REINSTALLEE = 'REINSTALLEE';
}
