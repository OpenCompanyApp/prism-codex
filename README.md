# Prism Codex

> Use your ChatGPT Pro/Plus subscription as a [Prism PHP](https://github.com/prism-php/prism) provider --- **$0 per token** via the Codex API. Part of the [OpenCompany](https://github.com/OpenCompanyApp) AI platform ecosystem.

OpenAI's [Codex](https://openai.com/index/introducing-codex/) environment, included with ChatGPT Pro and Plus subscriptions, provides access to powerful models (GPT-5, GPT-5 Codex, etc.) without per-token billing. Prism Codex bridges this into the PHP ecosystem so you can use these models in your Laravel applications through the standard Prism interface --- the same way you'd use OpenAI, Anthropic, or any other provider. It also integrates with the [Laravel AI SDK](https://github.com/laravel/ai) as a gateway driver.

## About OpenCompany

[OpenCompany](https://github.com/OpenCompanyApp) is an AI-powered workplace platform where teams deploy and coordinate multiple AI agents alongside human collaborators. It combines team messaging, document collaboration, task management, and intelligent automation in a single workspace --- with built-in approval workflows and granular permission controls so organizations can adopt AI agents safely and transparently.

Prism Codex was built to power OpenCompany's agent fleet on ChatGPT subscription models at zero marginal cost --- the same agents that handle analytics, astronomy, document generation, and more can now run on GPT-5 Codex without per-token API billing. If you're building with Laravel and want your AI features to run on your existing ChatGPT subscription, this package gives you that.

OpenCompany is built with Laravel, Vue 3, and Inertia.js. Learn more at [github.com/OpenCompanyApp](https://github.com/OpenCompanyApp).

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Prism PHP ^0.99 or ^1.0
- A ChatGPT Pro, Plus, or Team subscription

## Installation

```bash
composer require opencompany/prism-codex
```

Publish the config and run the migration:

```bash
php artisan vendor:publish --tag=codex-config
php artisan vendor:publish --tag=codex-migrations
php artisan migrate
```

The migration creates a `codex_tokens` table that stores OAuth credentials with Laravel's `encrypted` cast --- your tokens are AES-256-CBC encrypted at rest.

## Authentication

Codex uses OAuth 2.0 --- you log in with your ChatGPT account. Two methods are supported.

### Method A: Browser PKCE (recommended for local dev)

```bash
php artisan codex:login
```

Opens your browser, you log in at OpenAI, the CLI captures the callback on a local TCP server (port 9876), exchanges the authorization code using PKCE, and stores tokens automatically.

### Method B: Device Code (headless / servers)

```bash
php artisan codex:login --device
```

Displays a user code. Visit `https://auth.openai.com/codex/device`, enter the code, and the CLI polls until authorization completes. This is the preferred method for headless servers, CI/CD, and remote environments.

### Check Status

```bash
php artisan codex:status
```

### Logout

```bash
php artisan codex:logout
```

## Usage with Prism PHP

Once authenticated, use the `codex` provider like any other Prism provider:

```php
use Prism\Prism\Facades\Prism;

$response = Prism::text()
    ->using('codex', 'gpt-5-codex')
    ->withPrompt('Explain quantum computing in one paragraph.')
    ->asText();

echo $response->text;
```

### Tool Calling

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;

$weather = Tool::as('get_weather')
    ->for('Get current weather for a city')
    ->withStringParameter('city', 'The city name')
    ->using(fn (string $city) => "Weather in {$city}: 22C and sunny.");

$response = Prism::text()
    ->using('codex', 'gpt-5-codex')
    ->withTools([$weather])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather in Amsterdam?')
    ->asText();
```

### Streaming

```php
$stream = Prism::text()
    ->using('codex', 'gpt-5-codex')
    ->withPrompt('Write a haiku about PHP.')
    ->asStream();

foreach ($stream as $chunk) {
    echo $chunk->text;
}
```

### Structured Output

```php
$response = Prism::structured()
    ->using('codex', 'gpt-5-codex')
    ->withPrompt('List 3 programming languages and their creators.')
    ->withSchema(new ObjectSchema(/* ... */))
    ->asStructured();
```

## Usage with Laravel AI SDK

If you're using the [Laravel AI SDK](https://github.com/laravel/ai) (the official agent framework), you can register Codex as a custom gateway driver. The package already registers itself as a Prism provider. For the AI SDK, you need a gateway bridge class.

**1. Create the gateway class:**

```php
namespace App\Agents\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Gateway\Prism\PrismGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

class CodexPrismGateway extends PrismGateway
{
    public function __construct(Dispatcher $events)
    {
        parent::__construct($events);
    }

    protected function configure($prism, Provider $provider, string $model): mixed
    {
        return $prism->using('codex', $model);
    }

    protected function createPrismTextRequest(
        Provider $provider,
        string $model,
        ?array $schema,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ) {
        $resolvedTimeout = $timeout ?? (int) config('prism.request_timeout', 600);

        return parent::createPrismTextRequest(
            $provider, $model, $schema, $options, $resolvedTimeout,
        )->withClientOptions(['timeout' => $resolvedTimeout])
        ->withClientRetry(
            times: 2,
            sleepMilliseconds: 1000,
            when: fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException
                    && in_array($e->response->status(), [408, 429, 500, 502, 503, 504])),
            throw: true,
        );
    }
}
```

**2. Register in your `AppServiceProvider`:**

```php
use App\Agents\Providers\CodexPrismGateway;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\OpenAiProvider;

$this->app->afterResolving(AiManager::class, function (AiManager $aiManager, $app) {
    $aiManager->extend('codex', function ($app, array $config) {
        return new OpenAiProvider(
            new CodexPrismGateway($app['events']),
            $config,
            $app->make(\Illuminate\Contracts\Events\Dispatcher::class)
        );
    });
});
```

**3. Add the provider to `config/ai.php`:**

```php
'providers' => [
    'codex' => [
        'driver' => 'codex',
        'key' => 'codex-oauth', // Placeholder --- real auth uses OAuth tokens
    ],
    // ...
],
```

Now you can use Codex through the Laravel AI SDK agent system by setting `brain: 'codex:gpt-5-codex'` in your agent configuration.

## Available Models

| Model | Description |
|-------|-------------|
| `gpt-5-codex` | GPT-5 Codex |
| `gpt-5-codex-mini` | GPT-5 Codex Mini |
| `gpt-5` | GPT-5 |
| `gpt-5.2-codex` | GPT-5.2 Codex |
| `gpt-5.2` | GPT-5.2 |
| `gpt-5.1-codex` | GPT-5.1 Codex |
| `gpt-5.1-codex-max` | GPT-5.1 Codex Max |
| `gpt-5.1-codex-mini` | GPT-5.1 Codex Mini |

Model availability depends on your subscription tier. The Codex API may add or change model identifiers over time.

## How It Works

### Architecture

Prism Codex extends Prism's built-in OpenAI provider (`Prism\Prism\Providers\OpenAI\OpenAI`) and overrides the parts that differ between the standard OpenAI Responses API and the Codex API.

```
┌─────────────────────────────┐
│  Your Laravel Application   │
│  Prism::text()->using(...)  │
└────────────┬────────────────┘
             │
┌────────────▼────────────────┐
│  Codex Provider             │
│  extends OpenAI Provider    │
│                             │
│  Overrides:                 │
│  - client()     OAuth auth  │
│  - text()       CodexText   │
│  - stream()     CodexStream │
│  - handleResponseErrors()   │
└────────────┬────────────────┘
             │
┌────────────▼────────────────┐
│  chatgpt.com/backend-api/   │
│  codex/responses            │
└─────────────────────────────┘
```

### OAuth 2.0 Authentication

The package implements two standard OAuth flows using the same client ID that the official Codex CLI uses (`app_EMoamEEZ73f0CkXaXp7hrann`):

- **Browser PKCE**: Authorization Code flow with PKCE (S256). A temporary local TCP server on port 9876 captures the callback. Full browser-based login with PKCE code challenge/verifier.
- **Device Code**: OpenAI's device authorization endpoint at `auth.openai.com/api/accounts/deviceauth/usercode`. Generates a user code, polls for completion, then exchanges the returned authorization code + code verifier for tokens.

Both flows terminate in a standard token exchange at `auth.openai.com/oauth/token`.

### Token Storage & Auto-Refresh

Tokens are stored in an encrypted database table (`codex_tokens`) using Laravel's `encrypted` cast. The table uses a single-row upsert pattern.

Access tokens are short-lived (~1 hour). The package automatically detects when a token is expiring within 60 seconds and refreshes it transparently using the stored refresh token. Your application code never needs to handle token lifecycle.

```php
// This "just works" --- token refresh is automatic
$token = $oauthService->getAccessToken(); // Returns a valid token, refreshing if needed
```

### Account ID Extraction

The Codex API requires a `ChatGPT-Account-Id` header. This is extracted from the JWT access/ID token by searching claims in priority order:

1. Root-level `chatgpt_account_id` claim
2. Namespaced `https://api.openai.com/auth` claim
3. `organizations[0].id` fallback

### Codex API Compatibility Layer

The Codex API at `chatgpt.com/backend-api/codex/responses` has several differences from the standard OpenAI Responses API:

| Requirement | Standard OpenAI | Codex API |
|-------------|----------------|-----------|
| System prompt | In `input` array as `role: system` | Top-level `instructions` field, NOT in `input` |
| Streaming | Optional | **Mandatory** (`stream: true` required) |
| Storage | Configurable | Must be `store: false` |
| Auth | `Authorization: Bearer sk-...` (API key) | `Authorization: Bearer <oauth_token>` + `ChatGPT-Account-Id` header |
| Tool schemas | Flexible | Strict JSON Schema validation (arrays require `items`) |

The package handles all of these transparently:

**CodexText handler** --- For non-streaming Prism calls, the handler sends a streaming request (mandatory), buffers the full SSE response, parses out the `response.completed` event, and constructs a synthetic `ClientResponse` with the complete response JSON. The parent `Text` handler then processes it as normal.

**CodexStream handler** --- For streaming Prism calls, the handler reformats the request with `instructions`, `store: false`, and proper tool schemas, then delegates to the parent `Stream` handler for SSE parsing.

**SanitizesToolSchemas trait** --- The Codex API enforces strict JSON Schema validation on tool definitions. Any `array` type property missing an `items` definition causes a `400` error. This trait recursively walks all tool parameter schemas and adds `items: { type: "string" }` to bare arrays. Applied as a global safety net on both handlers so third-party tools (MCP servers, etc.) work without modification.

### Error Handling

The Codex API returns errors in a slightly different format than standard OpenAI. The `handleResponseErrors()` override parses multiple possible error shapes:

```
error.message → detail → message → raw body
```

All errors are logged with status and body, then re-thrown as `PrismException` with provider name "Codex" for clean integration with Prism's error pipeline.

## Web Integration

For web apps (not CLI), the package registers two routes:

- `GET /auth/codex/redirect` --- Initiates the OAuth PKCE flow
- `GET /auth/codex/callback` --- Handles the OAuth callback

You can also use `CodexOAuthService` directly for programmatic auth flows:

```php
use OpenCompany\PrismCodex\CodexOAuthService;

$oauth = app(CodexOAuthService::class);

// Device auth
$result = $oauth->initiateDeviceAuth();
// $result['device_auth_id'], $result['user_code']

// Poll
$tokens = $oauth->pollDeviceAuth($deviceAuthId, $userCode);
if ($tokens) {
    $oauth->storeTokens($tokens);
}

// Get current token (auto-refreshes)
$accessToken = $oauth->getAccessToken();
```

## Configuration

Published to `config/codex.php`:

```php
return [
    'url' => env('CODEX_URL', 'https://chatgpt.com/backend-api/codex'),
    'oauth_port' => env('CODEX_OAUTH_PORT', 9876),
    'callback_route' => env('CODEX_CALLBACK_ROUTE', '/auth/codex/callback'),
    'table' => env('CODEX_TOKEN_TABLE', 'codex_tokens'),
];
```

## Package Structure

```
src/
├── Codex.php                    # Main provider (extends OpenAI)
├── CodexOAuthService.php        # OAuth flows, token refresh, JWT parsing
├── CodexTokenStore.php          # Encrypted Eloquent model (single-row upsert)
├── CodexServiceProvider.php     # Auto-discovery, PrismManager registration
├── Handlers/
│   ├── CodexText.php            # SSE-to-sync bridge for non-streaming calls
│   └── CodexStream.php          # Streaming handler with Codex request format
├── Concerns/
│   └── SanitizesToolSchemas.php # Recursive array items fixer for strict validation
├── Console/
│   ├── CodexLoginCommand.php    # codex:login (browser PKCE + device code)
│   ├── CodexStatusCommand.php   # codex:status
│   └── CodexLogoutCommand.php   # codex:logout
└── Http/
    └── Controllers/
        └── CodexCallbackController.php  # Web OAuth callback handler
```

## Legal

OpenAI provides the Codex API (`chatgpt.com/backend-api/codex/`) as the official endpoint for ChatGPT Codex, using standard OAuth 2.0 authentication --- the same flow their own [Codex CLI](https://github.com/openai/codex) uses, with the same client ID.

This package is not affiliated with or endorsed by OpenAI. Use it in accordance with [OpenAI's usage policies](https://openai.com/policies/terms-of-use/) and your subscription terms.

## License

MIT License. See [LICENSE](LICENSE) for details.
