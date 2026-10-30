<?php

declare(strict_types=1);

namespace Koh\SemanticDelivery\Tests\Unit\Application\Service;

use Koh\SemanticDelivery\Application\Service\OAuthTokenService;
use Koh\SemanticDelivery\Tests\Unit\Support\DatabaseDouble;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthTokenServiceTest extends TestCase
{
    use DatabaseDouble;

    private mixed $previousConfig;

    protected function setUp(): void
    {
        $this->previousConfig = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-key';
        $this->provideTcaSchemaFactory();
    }

    protected function tearDown(): void
    {
        $this->releaseTcaSchemaFactory();
        if (null === $this->previousConfig) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->previousConfig;
        }
    }

    /**
     * Stores a token against an empty table and returns the row that was inserted.
     *
     * @return array<string, mixed>
     */
    private function storedRow(string $access, string $refresh, int $expiresAt = 0): array
    {
        $this->queryCalls = [];
        $service = new OAuthTokenService($this->pool($this->queryBuilder(null)));
        $service->storeToken('linkedin', 'site', $access, $refresh, $expiresAt, 'w_member_social', 'acc-1', 'Ole', ['k' => 'v']);

        return $this->callsTo('values')[0][0];
    }

    #[Test]
    public function insertsEncryptedTokensForNewAccount(): void
    {
        $row = $this->storedRow('secret-access', 'secret-refresh', 1234);

        self::assertSame('linkedin', $row['platform']);
        self::assertSame('site', $row['site_identifier']);
        self::assertSame(1234, $row['expires_at']);
        self::assertSame('{"k":"v"}', $row['metadata']);
        self::assertSame(1, $row['key_version']);
        self::assertSame('bearer', $row['token_type']);
        self::assertArrayHasKey('crdate', $row);
        self::assertStringNotContainsString('secret-access', $row['access_token']);
        self::assertStringNotContainsString('secret-access', (string) base64_decode($row['access_token'], true));
        self::assertNotSame($row['access_token'], $this->storedRow('secret-access', '')['access_token'], 'A fresh IV is used per encryption');
    }

    #[Test]
    public function storedTokenRoundTripsThroughGetToken(): void
    {
        $row = $this->storedRow('secret-access', 'secret-refresh', 4102444800);
        $service = new OAuthTokenService($this->pool($this->queryBuilder($row)));

        self::assertSame([
            'accessToken' => 'secret-access',
            'refreshToken' => 'secret-refresh',
            'expiresAt' => 4102444800,
            'accountId' => 'acc-1',
            'accountName' => 'Ole',
        ], $service->getToken('linkedin', 'site'));
    }

    #[Test]
    public function emptyRefreshTokenStaysEmpty(): void
    {
        $row = $this->storedRow('a', '');
        $service = new OAuthTokenService($this->pool($this->queryBuilder($row)));

        self::assertSame('', $row['refresh_token']);
        self::assertSame('', $service->getToken('linkedin', 'site')['refreshToken']);
    }

    #[Test]
    public function unknownAccountHasNoTokenAndCountsAsExpired(): void
    {
        $service = new OAuthTokenService($this->pool($this->queryBuilder(null)));

        self::assertNull($service->getToken('linkedin', 'site'));
        self::assertTrue($service->isTokenExpired('linkedin', 'site'));
    }

    #[Test]
    public function expiryIsEvaluatedAgainstTheClock(): void
    {
        $expired = new OAuthTokenService($this->pool($this->queryBuilder($this->storedRow('a', '', time() - 60))));
        $valid = new OAuthTokenService($this->pool($this->queryBuilder($this->storedRow('a', '', time() + 3600))));
        $never = new OAuthTokenService($this->pool($this->queryBuilder($this->storedRow('a', '', 0))));

        self::assertTrue($expired->isTokenExpired('linkedin', 'site'));
        self::assertFalse($valid->isTokenExpired('linkedin', 'site'));
        self::assertFalse($never->isTokenExpired('linkedin', 'site'), 'expires_at 0 means the token does not expire');
    }

    #[Test]
    public function existingAccountIsUpdatedWithTypedParameters(): void
    {
        $existing = ['uid' => 5] + $this->storedRow('old', 'old');
        $this->queryCalls = [];
        $qb = $this->queryBuilder($existing);
        $service = new OAuthTokenService($this->pool($qb));

        $service->storeToken('linkedin', 'site', 'new', 'new-refresh', 99, 'scope', 'acc', 'Name');

        self::assertSame([], $this->callsTo('insert'));
        self::assertCount(1, $this->callsTo('update'));
        $fields = array_map(static fn(array $call): string => $call[0], $this->callsTo('set'));
        self::assertContains('access_token', $fields);
        self::assertContains('expires_at', $fields);
        self::assertNotContains('platform', $fields, 'Identity columns are not rewritten on update');
    }

    #[Test]
    public function deleteRemovesTheTokenRow(): void
    {
        $qb = $this->queryBuilder();
        (new OAuthTokenService($this->pool($qb)))->deleteToken('linkedin', 'site');

        self::assertCount(1, $this->callsTo('delete'));
    }

    #[Test]
    public function tokensWithoutEncryptionKeyFallBackToLegacyEncodingAndLegacyKeys(): void
    {
        // A row written with the previous key version is still readable once the version was bumped:
        // simulate it by encrypting with the version-1 key but declaring the row as version 2.
        $key = substr(hash('sha256', 'unit-test-key:v1'), 0, 32);
        $iv = random_bytes(16);
        $cipher = base64_encode($iv . openssl_encrypt('legacy-secret', 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv));
        $row = [
            'access_token' => $cipher,
            'refresh_token' => '',
            'expires_at' => 0,
            'account_id' => 'a',
            'account_name' => 'n',
            'key_version' => 2,
        ];
        $service = new OAuthTokenService($this->pool($this->queryBuilder($row)));

        self::assertSame('legacy-secret', $service->getToken('p', 's')['accessToken']);
    }

    #[Test]
    public function undecodableValueIsReturnedAsIs(): void
    {
        $row = ['access_token' => '%%% not base64 %%%', 'refresh_token' => '', 'expires_at' => 0, 'account_id' => 'a', 'account_name' => 'n'];
        $service = new OAuthTokenService($this->pool($this->queryBuilder($row)));

        self::assertSame('%%% not base64 %%%', $service->getToken('p', 's')['accessToken']);
    }
}
