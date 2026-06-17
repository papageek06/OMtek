<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;

final class MicrosoftGraphContactSyncService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_SCOPE = 'https://graph.microsoft.com/.default';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{fetched:int, created:int, updated:int, unchanged:int, skipped:int}
     */
    public function sync(bool $dryRun = false, int $batchSize = 50, ?int $maxContacts = null): array
    {
        $config = $this->getConfig();
        $token = $this->requestAccessToken($config);
        $url = $this->buildContactsUrl($config, $batchSize);

        $stats = [
            'fetched' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];

        while ($url !== null) {
            $payload = $this->requestJson('GET', $url, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ]);

            $items = $payload['value'] ?? null;
            if (!is_array($items)) {
                throw new \RuntimeException('Reponse Microsoft Graph invalide: champ "value" manquant.');
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($maxContacts !== null && $stats['fetched'] >= $maxContacts) {
                    break 2;
                }

                $stats['fetched']++;
                $status = $this->upsertContact($item, $dryRun);
                $stats[$status]++;
            }

            $nextLink = $payload['@odata.nextLink'] ?? null;
            $url = is_string($nextLink) && $nextLink !== '' ? $nextLink : null;

            if (!$dryRun) {
                $this->em->flush();
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return 'created'|'updated'|'unchanged'|'skipped'
     */
    private function upsertContact(array $item, bool $dryRun): string
    {
        $exchangeId = $this->stringOrNull($item['id'] ?? null, 255);
        if ($exchangeId === null) {
            return 'skipped';
        }

        $mapped = $this->mapGraphContact($item, $exchangeId);
        if ($mapped['displayName'] === '') {
            return 'skipped';
        }

        /** @var Contact|null $contact */
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['exchangeId' => $exchangeId]);
        $created = false;

        if (!$contact instanceof Contact) {
            $contact = new Contact();
            $contact->setExchangeId($exchangeId);
            $created = true;
        }

        $currentChangeKey = $contact->getExchangeChangeKey();
        $incomingChangeKey = $mapped['exchangeChangeKey'];
        $unchanged = !$created && $incomingChangeKey !== null && $currentChangeKey === $incomingChangeKey;

        if (!$unchanged) {
            $contact
                ->setExchangeChangeKey($incomingChangeKey)
                ->setDisplayName($mapped['displayName'])
                ->setFirstName($mapped['firstName'])
                ->setLastName($mapped['lastName'])
                ->setEmail($mapped['email'])
                ->setMobilePhone($mapped['mobilePhone'])
                ->setBusinessPhone($mapped['businessPhone'])
                ->setCompanyName($mapped['companyName'])
                ->setJobTitle($mapped['jobTitle'])
                ->setNotes($mapped['notes']);
        }

        $contact->setSyncedAt(new \DateTimeImmutable());

        if (!$dryRun) {
            $this->em->persist($contact);
        }

        if ($created) {
            return 'created';
        }

        return $unchanged ? 'unchanged' : 'updated';
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{
     *     exchangeChangeKey:string|null,
     *     displayName:string,
     *     firstName:string|null,
     *     lastName:string|null,
     *     email:string|null,
     *     mobilePhone:string|null,
     *     businessPhone:string|null,
     *     companyName:string|null,
     *     jobTitle:string|null,
     *     notes:string|null
     * }
     */
    private function mapGraphContact(array $item, string $exchangeId): array
    {
        $firstName = $this->stringOrNull($item['givenName'] ?? null, 100);
        $lastName = $this->stringOrNull($item['surname'] ?? null, 100);
        $email = $this->firstEmail($item['emailAddresses'] ?? null);
        $mobilePhone = $this->stringOrNull($item['mobilePhone'] ?? null, 60);
        $businessPhone = $this->firstString($item['businessPhones'] ?? null, 60);

        $displayName = $this->stringOrNull($item['displayName'] ?? null, 180)
            ?? $this->stringOrNull(trim((string) $firstName . ' ' . (string) $lastName), 180)
            ?? $email
            ?? $mobilePhone
            ?? $businessPhone
            ?? ('Contact Exchange ' . mb_substr($exchangeId, 0, 12));

        return [
            'exchangeChangeKey' => $this->stringOrNull($item['changeKey'] ?? null, 255),
            'displayName' => $this->truncate($displayName, 180),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'mobilePhone' => $mobilePhone,
            'businessPhone' => $businessPhone,
            'companyName' => $this->stringOrNull($item['companyName'] ?? null, 180),
            'jobTitle' => $this->stringOrNull($item['jobTitle'] ?? null, 180),
            'notes' => $this->stringOrNull($item['personalNotes'] ?? null),
        ];
    }

    /**
     * @return array{tenantId:string, clientId:string, clientSecret:string, contactsUserId:string, contactsFolderId:string|null}
     */
    private function getConfig(): array
    {
        $config = [
            'tenantId' => $this->env('MICROSOFT_GRAPH_TENANT_ID'),
            'clientId' => $this->env('MICROSOFT_GRAPH_CLIENT_ID'),
            'clientSecret' => $this->env('MICROSOFT_GRAPH_CLIENT_SECRET'),
            'contactsUserId' => $this->env('MICROSOFT_GRAPH_CONTACTS_USER_ID'),
            'contactsFolderId' => $this->env('MICROSOFT_GRAPH_CONTACTS_FOLDER_ID') ?: null,
        ];

        foreach (['tenantId', 'clientId', 'clientSecret', 'contactsUserId'] as $key) {
            if ($config[$key] === '') {
                throw new \RuntimeException(sprintf('Variable Microsoft Graph manquante: %s.', $key));
            }
        }

        return $config;
    }

    /**
     * @param array{tenantId:string, clientId:string, clientSecret:string, contactsUserId:string, contactsFolderId:string|null} $config
     */
    private function requestAccessToken(array $config): string
    {
        $url = sprintf(
            'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
            rawurlencode($config['tenantId'])
        );

        $payload = $this->requestJson('POST', $url, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query([
            'client_id' => $config['clientId'],
            'client_secret' => $config['clientSecret'],
            'scope' => self::TOKEN_SCOPE,
            'grant_type' => 'client_credentials',
        ], '', '&', PHP_QUERY_RFC3986));

        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Token Microsoft Graph absent de la reponse OAuth.');
        }

        return $token;
    }

    /**
     * @param array{tenantId:string, clientId:string, clientSecret:string, contactsUserId:string, contactsFolderId:string|null} $config
     */
    private function buildContactsUrl(array $config, int $batchSize): string
    {
        $path = sprintf('/users/%s', rawurlencode($config['contactsUserId']));
        if ($config['contactsFolderId'] !== null && $config['contactsFolderId'] !== '') {
            $path .= sprintf('/contactFolders/%s/contacts', rawurlencode($config['contactsFolderId']));
        } else {
            $path .= '/contacts';
        }

        return self::GRAPH_BASE_URL . $path . '?' . http_build_query([
            '$select' => implode(',', [
                'id',
                'changeKey',
                'displayName',
                'givenName',
                'surname',
                'emailAddresses',
                'mobilePhone',
                'businessPhones',
                'companyName',
                'jobTitle',
                'personalNotes',
            ]),
            '$top' => max(1, min(999, $batchSize)),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, array $headers, ?string $body = null): array
    {
        if (function_exists('curl_init')) {
            return $this->requestJsonWithCurl($method, $url, $headers, $body);
        }

        return $this->requestJsonWithStreams($method, $url, $headers, $body);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function requestJsonWithCurl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Impossible d initialiser cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $caCertPath = $this->caCertPath();
        if ($caCertPath !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caCertPath);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Erreur reseau Microsoft Graph: ' . $error);
        }

        return $this->decodeGraphResponse((string) $response, $statusCode);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function requestJsonWithStreams(string $method, string $url, array $headers, ?string $body): array
    {
        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 60,
            ],
        ];

        $caCertPath = $this->caCertPath();
        if ($caCertPath !== null) {
            $options['ssl'] = [
                'cafile' => $caCertPath,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ];
        }

        $context = stream_context_create($options);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            throw new \RuntimeException('Erreur reseau Microsoft Graph: ' . ($error['message'] ?? 'raison inconnue'));
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
                break;
            }
        }

        return $this->decodeGraphResponse($response, $statusCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeGraphResponse(string $response, int $statusCode): array
    {
        try {
            $payload = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Reponse Microsoft Graph non JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($payload)) {
            throw new \RuntimeException('Reponse Microsoft Graph invalide.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = $payload['error']['message'] ?? $payload['error_description'] ?? 'erreur inconnue';
            throw new \RuntimeException(sprintf('Microsoft Graph HTTP %d: %s', $statusCode, (string) $message));
        }

        return $payload;
    }

    private function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private function caCertPath(): ?string
    {
        $path = $this->env('MICROSOFT_GRAPH_CA_CERT_PATH');
        if ($path === '') {
            return null;
        }

        if (!is_file($path)) {
            throw new \RuntimeException('Chemin CA Microsoft Graph introuvable: ' . $path);
        }

        return $path;
    }

    private function stringOrNull(mixed $value, ?int $maxLength = null): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return $maxLength === null ? $string : $this->truncate($string, $maxLength);
    }

    private function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) : $value;
    }

    private function firstEmail(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $emailAddress) {
            if (!is_array($emailAddress)) {
                continue;
            }

            $email = $this->stringOrNull($emailAddress['address'] ?? null, 180);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    private function firstString(mixed $value, int $maxLength): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $string = $this->stringOrNull($item, $maxLength);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }
}
