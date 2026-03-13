<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum InterventionApprovalStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}

