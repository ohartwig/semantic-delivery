<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Application\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

final class OAuthTokenService
{
    private const TABLE = 'tx_semanticdelivery_oauth_token';

    private const INTEGER_COLUMNS = ['tstamp', 'crdate', 'expires_at', 'key_version'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function storeToken(
        string $platform,
        string $siteIdentifier,
        string $accessToken,
        string $refreshToken,
        int $expiresAt,
        string $scope,
        string $accountId,
        string $accountName,
        array $metadata = [],
    ): void {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        $existing = $this->findToken($platform, $siteIdentifier);

        $data = [
            'tstamp' => time(),
            'access_token' => $this->encrypt($accessToken),
            'refresh_token' => '' !== $refreshToken ? $this->encrypt($refreshToken) : '',
            'token_type' => 'bearer',
            'expires_at' => $expiresAt,
            'scope' => $scope,
            'account_id' => $accountId,
            'account_name' => $accountName,
            'metadata' => json_encode($metadata),
            'key_version' => self::CURRENT_KEY_VERSION,
        ];

        if (null !== $existing) {
            $queryBuilder
                ->update(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter((int) $existing['uid'], ParameterType::INTEGER)),
                );

            foreach ($data as $field => $value) {
                $type = self::parameterTypeFor($field);
                $queryBuilder->set(
                    $field,
                    $queryBuilder->createNamedParameter(
                        ParameterType::INTEGER === $type ? (int) $value : (string) $value,
                        $type,
                    ),
                    false,
                );
            }

            $queryBuilder->executeStatement();
        } else {
            $data['crdate'] = time();
            $data['platform'] = $platform;
            $data['site_identifier'] = $siteIdentifier;

            $queryBuilder
                ->insert(self::TABLE)
                ->values($data)
                ->executeStatement();
        }
    }

    /**
     * @return array{accessToken: string, refreshToken: string, expiresAt: int, accountId: string, accountName: string}|null
     */
    public function getToken(string $platform, string $siteIdentifier): ?array
    {
        $row = $this->findToken($platform, $siteIdentifier);
        if (null === $row) {
            return null;
        }

        $keyVersion = (int) ($row['key_version'] ?? self::CURRENT_KEY_VERSION);

        return [
            'accessToken' => $this->decrypt((string) $row['access_token'], $keyVersion),
            'refreshToken' => '' !== $row['refresh_token'] ? $this->decrypt((string) $row['refresh_token'], $keyVersion) : '',
            'expiresAt' => (int) $row['expires_at'],
            'accountId' => (string) $row['account_id'],
            'accountName' => (string) $row['account_name'],
        ];
    }

    public function isTokenExpired(string $platform, string $siteIdentifier): bool
    {
        $token = $this->getToken($platform, $siteIdentifier);
        if (null === $token) {
            return true;
        }

        if (0 === $token['expiresAt']) {
            return false;
        }

        return $token['expiresAt'] < time();
    }

    public function deleteToken(string $platform, string $siteIdentifier): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('platform', $queryBuilder->createNamedParameter($platform)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findToken(string $platform, string $siteIdentifier): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('platform', $queryBuilder->createNamedParameter($platform)),
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false !== $row ? $row : null;
    }

    private const CURRENT_KEY_VERSION = 1;

    private static function parameterTypeFor(string $column): ParameterType
    {
        return in_array($column, self::INTEGER_COLUMNS, true)
            ? ParameterType::INTEGER
            : ParameterType::STRING;
    }

    private function encrypt(string $value): string
    {
        $key = $this->getEncryptionKeyForVersion(self::CURRENT_KEY_VERSION);
        if ('' === $key) {
            return base64_encode($value);
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return false !== $encrypted ? base64_encode($iv . $encrypted) : base64_encode($value);
    }

    private function decrypt(string $value, int $keyVersion = self::CURRENT_KEY_VERSION): string
    {
        $key = $this->getEncryptionKeyForVersion($keyVersion);
        $raw = base64_decode($value, true);

        if (false === $raw) {
            return $value;
        }

        if ('' === $key || strlen($raw) < 17) {
            return $raw;
        }

        $iv = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if (false !== $decrypted) {
            return $decrypted;
        }

        // Fallback: try previous key versions
        for ($v = $keyVersion - 1; $v >= 1; --$v) {
            $fallbackKey = $this->getEncryptionKeyForVersion($v);
            if ('' === $fallbackKey || $fallbackKey === $key) {
                continue;
            }
            $fallback = openssl_decrypt($encrypted, 'aes-256-cbc', $fallbackKey, OPENSSL_RAW_DATA, $iv);
            if (false !== $fallback) {
                return $fallback;
            }
        }

        return $raw;
    }

    private function getEncryptionKeyForVersion(int $version): string
    {
        $baseKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        return substr(hash('sha256', $baseKey . ':v' . $version), 0, 32);
    }
}
