<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Tests;

use Illuminate\Support\Facades\Http;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\CodexTokenStore;

class CodexLoginCommandTest extends TestCase
{
    public function test_device_flow_displays_user_code(): void
    {
        Http::fake([
            'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
                'device_auth_id' => 'dev_test',
                'user_code' => 'WXYZ-5678',
                'interval' => 1,
            ]),
            'auth.openai.com/api/accounts/deviceauth/token' => Http::sequence()
                ->push(['error' => 'authorization_pending'], 403)
                ->push([
                    'authorization_code' => 'auth-code',
                    'code_verifier' => 'verifier',
                ]),
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => $this->buildFakeJwt(['email' => 'test@test.com']),
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        // We can't easily test the full interactive flow, but we can test device auth
        // through the OAuth service directly
        $service = new CodexOAuthService;

        $device = $service->initiateDeviceAuth();
        $this->assertEquals('WXYZ-5678', $device['user_code']);
        $this->assertEquals('dev_test', $device['device_auth_id']);
    }

    public function test_device_flow_stores_tokens_on_success(): void
    {
        Http::fake([
            'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
                'authorization_code' => 'auth-code',
                'code_verifier' => 'verifier',
            ]),
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => $this->buildFakeJwt([
                    'email' => 'user@test.com',
                    'chatgpt_account_id' => 'acct_device',
                ]),
                'refresh_token' => 'device-refresh',
                'expires_in' => 3600,
                'id_token' => $this->buildFakeJwt(['email' => 'user@test.com']),
            ]),
        ]);

        $service = new CodexOAuthService;
        $tokens = $service->pollDeviceAuth('dev_123', 'CODE-1234');

        $this->assertNotNull($tokens);

        $service->storeTokens($tokens);

        $stored = CodexTokenStore::current();
        $this->assertNotNull($stored);
        $this->assertEquals('device-refresh', $stored->refresh_token);
    }

    public function test_status_shows_not_configured(): void
    {
        $this->artisan('codex:status')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();
    }

    public function test_status_shows_active_token(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_test',
            'email' => 'user@example.com',
        ]);

        $this->artisan('codex:status')
            ->expectsOutputToContain('user@example.com')
            ->assertSuccessful();
    }

    public function test_logout_clears_tokens(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('codex:logout')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        $this->assertNull(CodexTokenStore::current());
    }

    public function test_logout_when_no_tokens(): void
    {
        $this->artisan('codex:logout')
            ->expectsOutputToContain('No Codex tokens')
            ->assertSuccessful();
    }
}
