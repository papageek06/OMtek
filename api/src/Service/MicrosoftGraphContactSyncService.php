<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contact;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class MicrosoftGraphContactSyncService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_SCOPE = 'https://graph.microsoft.com/.default';
    private const MAX_EXCHANGE_ID_LENGTH = 512;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *     enabled:bool,
     *     configured:bool,
     *     missing:list<string>,
     *     contactsUserIdConfigured:bool,
     *     contactsFolderIdConfigured:bool,
     *     caCertPathConfigured:bool,
     *     caCertPathValid:bool|null
     * }
     */
    public function configurationStatus(): array
    {
        $required = [
            'MICROSOFT_GRAPH_TENANT_ID',
            'MICROSOFT_GRAPH_CLIENT_ID',
            'MICROSOFT_GRAPH_CLIENT_SECRET',
            'MICROSOFT_GRAPH_CONTACTS_USER_ID',
        ];
        $missing = [];
        foreach ($required as $name) {
            if ($this->env($name) === '') {
                $missing[] = $name;
            }
        }

        $caCertPath = $this->env('MICROSOFT_GRAPH_CA_CERT_PATH');

        return [
            'enabled' => $this->boolEnv('MICROSOFT_GRAPH_SYNC_ENABLED'),
            'configured' => $missing === [],
            'missing' => $missing,
            'contactsUserIdConfigured' => $this->env('MICROSOFT_GRAPH_CONTACTS_USER_ID') !== '',
            'contactsFolderIdConfigured' => $this->env('MICROSOFT_GRAPH_CONTACTS_FOLDER_ID') !== '',
            'caCertPathConfigured' => $caCertPath !== '',
            'caCertPathValid' => $caCertPath === '' ? null : is_file($caCertPath),
        ];
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
        $seenExchangeIds = [];

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
                $exchangeId = $this->graphExchangeId($item['id'] ?? null);
                if ($exchangeId === null || isset($seenExchangeIds[$exchangeId])) {
                    $stats['skipped']++;
                    continue;
                }

                $seenExchangeIds[$exchangeId] = true;
                try {
                    $status = $this->upsertContact($item, $dryRun, $exchangeId);
                    if (!$dryRun) {
                        $this->em->flush();
                    }
                } catch (UniqueConstraintViolationException) {
                    $stats['skipped']++;
                    $this->em->clear();
                    continue;
                }
                $stats[$status]++;
            }

            $nextLink = $payload['@odata.nextLink'] ?? null;
            $url = is_string($nextLink) && $nextLink !== '' ? $nextLink : null;

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
    private function upsertContact(array $item, bool $dryRun, string $exchangeId): string
    {
        $mapped = $this->mapGraphContact($item, $exchangeId);
        if ($mapped['displayName'] === '') {
            return 'skipped';
        }

        $contact = $this->findExistingContact($exchangeId);
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
                ->setEmailAddresses($mapped['emailAddresses'])
                ->setMobilePhone($mapped['mobilePhone'])
                ->setBusinessPhone($mapped['businessPhone'])
                ->setPhoneNumbers($mapped['phoneNumbers'])
                ->setCompanyName($mapped['companyName'])
                ->setJobTitle($mapped['jobTitle'])
                ->setBusinessAddress($mapped['businessAddress'])
                ->setHomeAddress($mapped['homeAddress'])
                ->setOtherAddress($mapped['otherAddress'])
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
     *     emailAddresses:list<array{label:string|null, address:string}>|null,
     *     mobilePhone:string|null,
     *     businessPhone:string|null,
     *     phoneNumbers:list<array{type:string, number:string}>|null,
     *     companyName:string|null,
     *     jobTitle:string|null,
     *     businessAddress:array<string, string>|null,
     *     homeAddress:array<string, string>|null,
     *     otherAddress:array<string, string>|null,
     *     notes:string|null
     * }
     */
    private function mapGraphContact(array $item, string $exchangeId): array
    {
        $firstName = $this->stringOrNull($item['givenName'] ?? null, 100);
        $lastName = $this->stringOrNull($item['surname'] ?? null, 100);
        $email = $this->firstEmail($item['emailAddresses'] ?? null);
        $emailAddresses = $this->emailAddresses($item['emailAddresses'] ?? null);
        $mobilePhone = $this->stringOrNull($item['mobilePhone'] ?? null, 60);
        $businessPhone = $this->firstString($item['businessPhones'] ?? null, 60);
        $phoneNumbers = $this->phoneNumbers(
            $mobilePhone,
            $item['businessPhones'] ?? null,
            $item['homePhones'] ?? null
        );

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
            'emailAddresses' => $emailAddresses,
            'mobilePhone' => $mobilePhone,
            'businessPhone' => $businessPhone,
            'phoneNumbers' => $phoneNumbers,
            'companyName' => $this->stringOrNull($item['companyName'] ?? null, 180),
            'jobTitle' => $this->stringOrNull($item['jobTitle'] ?? null, 180),
            'businessAddress' => $this->postalAddress($item['businessAddress'] ?? null),
            'homeAddress' => $this->postalAddress($item['homeAddress'] ?? null),
            'otherAddress' => $this->postalAddress($item['otherAddress'] ?? null),
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
                'homePhones',
                'businessAddress',
                'homeAddress',
                'otherAddress',
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

    private function graphExchangeId(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        if (mb_strlen($string) <= self::MAX_EXCHANGE_ID_LENGTH) {
            return $string;
        }

        return 'graph-sha256:' . hash('sha256', $string);
    }

    private function findExistingContact(string $exchangeId): ?Contact
    {
        $legacyExchangeId = mb_substr($exchangeId, 0, 255);
        $qb = $this->em->getRepository(Contact::class)
            ->createQueryBuilder('contact')
            ->andWhere('contact.exchangeId = :exchangeId')
            ->setParameter('exchangeId', $exchangeId)
            ->setMaxResults(1);

        if ($legacyExchangeId !== $exchangeId) {
            $qb
                ->orWhere('contact.exchangeId = :legacyExchangeId')
                ->setParameter('legacyExchangeId', $legacyExchangeId);
        }

        /** @var Contact|null $contact */
        $contact = $qb->getQuery()->getOneOrNullResult();

        return $contact;
    }

    private function boolEnv(string $name): bool
    {
        $value = $this->env($name);
        if ($value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
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

    /**
     * @return list<array{label:string|null, address:string}>|null
     */
    private function emailAddresses(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $emailAddress) {
            if (!is_array($emailAddress)) {
                continue;
            }

            $address = $this->stringOrNull($emailAddress['address'] ?? null, 180);
            if ($address === null) {
                continue;
            }

            $items[] = [
                'label' => $this->stringOrNull($emailAddress['name'] ?? null, 180),
                'address' => $address,
            ];
        }

        return $items === [] ? null : $items;
    }

    /**
     * @return list<array{type:string, number:string}>|null
     */
    private function phoneNumbers(?string $mobilePhone, mixed $businessPhones, mixed $homePhones): ?array
    {
        $items = [];
        if ($mobilePhone !== null) {
            $items[] = ['type' => 'Mobile', 'number' => $mobilePhone];
        }

        foreach ($this->strings($businessPhones, 60) as $phone) {
            $items[] = ['type' => 'Professionnel', 'number' => $phone];
        }

        foreach ($this->strings($homePhones, 60) as $phone) {
            $items[] = ['type' => 'Personnel', 'number' => $phone];
        }

        $unique = [];
        foreach ($items as $item) {
            $key = mb_strtolower($item['type'] . ':' . $item['number']);
            $unique[$key] = $item;
        }

        $items = array_values($unique);

        return $items === [] ? null : $items;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value, int $maxLength): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $string = $this->stringOrNull($item, $maxLength);
            if ($string !== null) {
                $items[] = $string;
            }
        }

        return $items;
    }

    /**
     * @return array<string, string>|null
     */
    private function postalAddress(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $address = [];
        foreach ([
            'street' => 'Rue',
            'city' => 'Ville',
            'state' => 'Region',
            'postalCode' => 'Code postal',
            'countryOrRegion' => 'Pays',
        ] as $key => $label) {
            $string = $this->stringOrNull($value[$key] ?? null, 255);
            if ($string !== null) {
                $address[$label] = $string;
            }
        }

        return $address === [] ? null : $address;
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
