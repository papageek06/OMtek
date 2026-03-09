<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionSource: string
{
    case MANUEL = 'MANUEL';
    case ALERTE_MAIL = 'ALERTE_MAIL';
    case ABSENCE_SCAN = 'ABSENCE_SCAN';
    case SUPERVISION = 'SUPERVISION';
}
