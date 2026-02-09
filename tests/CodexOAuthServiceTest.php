<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Tests;

use Illuminate\Support\Facades\Http;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\CodexTokenStore;

class CodexOAuthServiceTest extends TestCase
{
    private CodexOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CodexOAuthService;
    }

    public function test_generate_pkce_produces_valid_verifier(): void
    {
        $pkce = $this->service->generatePkce();

        $this->assertArrayHasKey('verifier', $pkce);
        $this->assertArrayHasKey('challenge', $pkce);
        $this->assertEquals(43, strlen($pkce['verifier']));
    }

    public function test_generate_pkce_challenge_is_base64url_sha256(): void
    {
        $pkce = $this->service->generatePkce();

        // Challenge should be base64url-encoded SHA-256 of verifier
        $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['verifier'], true)), '+/', '-_'), '=');
        $this->assertEquals($expected, $pkce['challenge']);
    }

    public function test_build_authorization_url_includes_all_params(): void
    {
        $url = $this->service->buildAuthorizationUrl('test-challenge', 'test-state', 'http://localhost:9876/callback');

        $this->assertStringContainsString('auth.openai.com/oauth/authorize', $url);
        $this->assertStringContainsString('client_id='.CodexOAuthService::CLIENT_ID, $url);
        $this->assertStringContainsString('code_challenge=test-challenge', $url);
        $this->assertStringContainsString('state=test-state', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('codex_cli_simplified_flow=true', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString(urlencode('openid profile email offline_access'), $url);
    }

    public function test_exchange_code_posts_to_token_endpoint(): void
    {
        Http::fake([
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'id_token' => 'id-token',
            ]),
        ]);

        $result = $this->service->exchangeCode('auth-code', 'verifier', 'http://localhost/callback');

        $this->assertEquals('new-access', $result['access_token']);
        $this->assertEquals('new-refresh', $result['refresh_token']);
        $this->assertEquals(3600, $result['expires_in']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.openai.com/oauth/token')
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code'
                && $request['code_verifier'] === 'verifier'
                && $request['client_id'] === CodexOAuthService::CLIENT_ID;
        });
    }

    public function test_refresh_token_posts_refresh_grant(): void
    {
        CodexTokenStore::store([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->subMinute(),
            'account_id' => 'acct_123',
        ]);

        $jwt = $this->buildFakeJwt(['chatgpt_account_id' => 'acct_123']);

        Http::fake([
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => $jwt,
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $result = $this->service->refreshToken();

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.openai.com/oauth/token')
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'old-refresh'
                && $request['client_id'] === CodexOAuthService::CLIENT_ID;
        });

        $stored = CodexTokenStore::current();
        $this->assertEquals($jwt, $stored->access_token);
        $this->assertEquals('new-refresh', $stored->refresh_token);
    }

    public function test_refresh_token_returns_false_on_failure(): void
    {
        CodexTokenStore::store([
            'access_token' => 'old-access',
            'refresh_token' => 'old-refresh',
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'auth.openai.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $result = $this->service->refreshToken();

        $this->assertFalse($result);
    }

    public function test_refresh_token_returns_false_when_not_configured(): void
    {
        $this->assertFalse($this->service->refreshToken());
    }

    public function test_get_access_token_returns_valid_token(): void
    {
        CodexTokenStore::store([
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $token = $this->service->getAccessToken();

        $this->assertEquals('valid-token', $token);
    }

    public function test_get_access_token_auto_refreshes_when_expiring_soon(): void
    {
        CodexTokenStore::store([
            'access_token' => 'expiring-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addSeconds(30),
        ]);

        $newJwt = $this->buildFakeJwt(['email' => 'test@example.com']);

        Http::fake([
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => $newJwt,
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $token = $this->service->getAccessToken();

        $this->assertEquals($newJwt, $token);
        Http::assertSentCount(1);
    }

    public function test_get_access_token_returns_null_when_not_configured(): void
    {
        $this->assertNull($this->service->getAccessToken());
    }

    public function test_initiate_device_auth_posts_correctly(): void
    {
        Http::fake([
            'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
                'device_auth_id' => 'dev_123',
                'user_code' => 'ABCD-1234',
                'interval' => 5,
            ]),
        ]);

        $result = $this->service->initiateDeviceAuth();

        $this->assertEquals('dev_123', $result['device_auth_id']);
        $this->assertEquals('ABCD-1234', $result['user_code']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'deviceauth/usercode')
                && $request['client_id'] === CodexOAuthService::CLIENT_ID;
        });
    }

    public function test_poll_device_auth_returns_null_on_403(): void
    {
        Http::fake([
            'auth.openai.com/api/accounts/deviceauth/token' => Http::response(['error' => 'authorization_pending'], 403),
        ]);

        $result = $this->service->pollDeviceAuth('dev_123', 'ABCD-1234');

        $this->assertNull($result);
    }

    public function test_poll_device_auth_returns_tokens_on_success(): void
    {
        Http::fake([
            'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
                'authorization_code' => 'auth-code',
                'code_verifier' => 'verifier',
            ]),
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => 'device-access',
                'refresh_token' => 'device-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $result = $this->service->pollDeviceAuth('dev_123', 'ABCD-1234');

        $this->assertNotNull($result);
        $this->assertEquals('device-access', $result['access_token']);
    }

    public function test_extract_account_id_from_jwt_root_claim(): void
    {
        $jwt = $this->buildFakeJwt(['chatgpt_account_id' => 'acct_root']);

        $this->assertEquals('acct_root', $this->service->extractAccountIdFromJwt($jwt));
    }

    public function test_extract_account_id_from_jwt_namespace_claim(): void
    {
        $jwt = $this->buildFakeJwt([
            'https://api.openai.com/auth' => [
                'chatgpt_account_id' => 'acct_namespace',
            ],
        ]);

        $this->assertEquals('acct_namespace', $this->service->extractAccountIdFromJwt($jwt));
    }

    public function test_extract_account_id_from_jwt_org_array(): void
    {
        $jwt = $this->buildFakeJwt([
            'organizations' => [
                ['id' => 'org_123', 'name' => 'Test Org'],
            ],
        ]);

        $this->assertEquals('org_123', $this->service->extractAccountIdFromJwt($jwt));
    }

    public function test_extract_account_id_returns_null_for_empty_jwt(): void
    {
        $this->assertNull($this->service->extractAccountIdFromJwt(''));
    }

    public function test_extract_account_id_returns_null_for_invalid_jwt(): void
    {
        $this->assertNull($this->service->extractAccountIdFromJwt('not-a-jwt'));
    }

    public function test_extract_email_from_jwt(): void
    {
        $jwt = $this->buildFakeJwt(['email' => 'user@example.com']);

        $this->assertEquals('user@example.com', $this->service->extractEmailFromJwt($jwt));
    }

    public function test_store_tokens_extracts_account_id_and_email(): void
    {
        $jwt = $this->buildFakeJwt([
            'chatgpt_account_id' => 'acct_test',
            'email' => 'test@example.com',
        ]);

        $this->service->storeTokens([
            'access_token' => $jwt,
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
            'id_token' => $jwt,
        ]);

        $stored = CodexTokenStore::current();
        $this->assertEquals('acct_test', $stored->account_id);
        $this->assertEquals('test@example.com', $stored->email);
    }

    public function test_is_configured_returns_false_when_no_tokens(): void
    {
        $this->assertFalse($this->service->isConfigured());
    }

    public function test_is_configured_returns_true_when_tokens_stored(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue($this->service->isConfigured());
    }

    public function test_get_account_id_returns_stored_value(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_stored',
        ]);

        $this->assertEquals('acct_stored', $this->service->getAccountId());
    }
}
