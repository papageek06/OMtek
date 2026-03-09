<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionBillingStatus: string
{
    case A_FACTURER = 'A_FACTURER';
    case NON_FACTURE = 'NON_FACTURE';
}
