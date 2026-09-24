<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum CsvImportAnomalyStatus: string
{
    case OPEN = 'OPEN';
    case RESOLVED = 'RESOLVED';
    case IGNORED = 'IGNORED';
}
