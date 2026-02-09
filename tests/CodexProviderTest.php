<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Tests;

use Illuminate\Support\Facades\Http;
use OpenCompany\PrismCodex\Codex;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\CodexTokenStore;
use Prism\Prism\Providers\OpenAI\OpenAI;

class CodexProviderTest extends TestCase
{
    private CodexOAuthService $oauthService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oauthService = new CodexOAuthService;
    }

    public function test_provider_extends_openai(): void
    {
        $provider = new Codex($this->oauthService);

        $this->assertInstanceOf(OpenAI::class, $provider);
    }

    public function test_throws_when_not_authenticated(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Codex not authenticated');

        $provider = new Codex($this->oauthService);

        // Access the client via reflection to trigger the auth check
        $method = new \ReflectionMethod($provider, 'client');
        $method->invoke($provider);
    }

    public function test_client_uses_codex_base_url(): void
    {
        CodexTokenStore::store([
            'access_token' => 'test-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_test',
        ]);

        $provider = new Codex($this->oauthService);

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);

        // Check the base URL via reflection on the PendingRequest options
        $optionsProperty = new \ReflectionProperty($client, 'baseUrl');
        $baseUrl = $optionsProperty->getValue($client);

        $this->assertEquals('https://chatgpt.com/backend-api/codex', $baseUrl);
    }

    public function test_client_includes_oauth_bearer_token(): void
    {
        CodexTokenStore::store([
            'access_token' => 'my-oauth-token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'chatgpt.com/backend-api/codex/*' => Http::response([
                'id' => 'resp_123',
                'output' => [
                    [
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [['type' => 'output_text', 'text' => 'Hello']],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $provider = new Codex($this->oauthService);

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);

        // Make a test request to verify headers
        $client->post('responses', ['model' => 'gpt-5.3-codex', 'input' => 'test']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-oauth-token');
        });
    }

    public function test_client_includes_account_id_header_when_set(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_org_123',
        ]);

        Http::fake([
            'chatgpt.com/backend-api/codex/*' => Http::response(['id' => 'resp_123', 'output' => [], 'usage' => []]),
        ]);

        $provider = new Codex($this->oauthService);

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);
        $client->post('responses', ['model' => 'test']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('ChatGPT-Account-Id', 'acct_org_123');
        });
    }

    public function test_client_omits_account_id_when_null(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => null,
        ]);

        Http::fake([
            'chatgpt.com/backend-api/codex/*' => Http::response(['id' => 'resp_123', 'output' => [], 'usage' => []]),
        ]);

        $provider = new Codex($this->oauthService);

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);
        $client->post('responses', ['model' => 'test']);

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('ChatGPT-Account-Id');
        });
    }

    public function test_uses_explicit_account_id_over_stored(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
            'account_id' => 'acct_stored',
        ]);

        Http::fake([
            'chatgpt.com/backend-api/codex/*' => Http::response(['id' => 'resp_123', 'output' => [], 'usage' => []]),
        ]);

        $provider = new Codex($this->oauthService, accountId: 'acct_explicit');

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);
        $client->post('responses', ['model' => 'test']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('ChatGPT-Account-Id', 'acct_explicit');
        });
    }

    public function test_custom_base_url(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $provider = new Codex(
            $this->oauthService,
            url: 'https://custom.endpoint.com/api',
        );

        $method = new \ReflectionMethod($provider, 'client');
        $client = $method->invoke($provider);

        $optionsProperty = new \ReflectionProperty($client, 'baseUrl');
        $baseUrl = $optionsProperty->getValue($client);

        $this->assertEquals('https://custom.endpoint.com/api', $baseUrl);
    }

    public function test_auto_refreshes_expired_token_before_request(): void
    {
        CodexTokenStore::store([
            'access_token' => 'expiring-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addSeconds(30), // Will trigger refresh
        ]);

        $newJwt = $this->buildFakeJwt(['email' => 'refreshed@test.com']);

        Http::fake([
            'auth.openai.com/oauth/token' => Http::response([
                'access_token' => $newJwt,
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
        ]);

        $token = $this->oauthService->getAccessToken();

        $this->assertEquals($newJwt, $token);
    }

    public function test_provider_can_be_resolved_from_prism_manager(): void
    {
        CodexTokenStore::store([
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_at' => now()->addHour(),
        ]);

        $manager = $this->app->make(\Prism\Prism\PrismManager::class);
        $provider = $manager->resolve('codex', []);

        $this->assertInstanceOf(Codex::class, $provider);
    }
}
