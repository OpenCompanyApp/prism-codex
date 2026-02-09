<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Tests;

use OpenCompany\PrismCodex\CodexTokenStore;

class CodexTokenStoreTest extends TestCase
{
    public function test_stores_and_retrieves_tokens(): void
    {
        $token = CodexTokenStore::store([
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_123',
            'email' => 'user@example.com',
        ]);

        $this->assertNotNull($token->id);

        $current = CodexTokenStore::current();
        $this->assertNotNull($current);
        $this->assertEquals('test-access-token', $current->access_token);
        $this->assertEquals('test-refresh-token', $current->refresh_token);
        $this->assertEquals('acct_123', $current->account_id);
        $this->assertEquals('user@example.com', $current->email);
    }

    public function test_upserts_single_row(): void
    {
        CodexTokenStore::store([
            'access_token' => 'first-token',
            'refresh_token' => 'first-refresh',
            'expires_at' => now()->addHour(),
        ]);

        CodexTokenStore::store([
            'access_token' => 'second-token',
            'refresh_token' => 'second-refresh',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertEquals(1, CodexTokenStore::query()->count());

        $current = CodexTokenStore::current();
        $this->assertEquals('second-token', $current->access_token);
    }

    public function test_clears_tokens(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertNotNull(CodexTokenStore::current());

        CodexTokenStore::clear();

        $this->assertNull(CodexTokenStore::current());
    }

    public function test_checks_expiry(): void
    {
        $token = CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($token->isExpired());
    }

    public function test_checks_expiring_soon(): void
    {
        $token = CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addSeconds(30),
        ]);

        $this->assertTrue($token->isExpiringSoon(60));
        $this->assertFalse($token->isExpiringSoon(10));
    }

    public function test_current_returns_null_when_empty(): void
    {
        $this->assertNull(CodexTokenStore::current());
    }

    public function test_stores_token_data_as_encrypted_array(): void
    {
        $token = CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'token_data' => ['id_token' => 'some-id-token'],
        ]);

        $fresh = $token->fresh();
        $this->assertIsArray($fresh->token_data);
        $this->assertEquals('some-id-token', $fresh->token_data['id_token']);
    }
}
