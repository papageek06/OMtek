<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InboundTokenGuard
{
    public function __construct(
        #[Autowire('%env(string:default::INBOUND_TOKEN)%')]
        private readonly string $configuredToken,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->configuredToken) !== '';
    }

    public function isValid(?string $providedToken): bool
    {
        $configured = trim($this->configuredToken);
        $provided = trim((string) $providedToken);

        if ($configured === '' || $provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }
}
