<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionPriority: string
{
    case BASSE = 'BASSE';
    case NORMALE = 'NORMALE';
    case HAUTE = 'HAUTE';
    case CRITIQUE = 'CRITIQUE';
}
