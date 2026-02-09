<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Generator;
use OpenCompany\PrismCodex\Handlers\CodexStream;
use OpenCompany\PrismCodex\Handlers\CodexText;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Codex extends OpenAI
{
    public function __construct(
        private readonly CodexOAuthService $oauthService,
        string $url = 'https://chatgpt.com/backend-api/codex',
        private readonly ?string $accountId = null,
    ) {
        parent::__construct(
            apiKey: 'codex-oauth-placeholder',
            url: $url,
            organization: null,
            project: null,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    #[\Override]
    protected function client(array $options = [], array $retry = [], ?string $baseUrl = null): PendingRequest
    {
        $token = $this->oauthService->getAccessToken();

        if (! $token) {
            throw new \RuntimeException(
                'Codex not authenticated. Run: php artisan codex:login'
            );
        }

        $headers = array_filter([
            'ChatGPT-Account-Id' => $this->accountId ?? $this->oauthService->getAccountId(),
        ]);

        return $this->baseClient()
            ->withHeaders($headers)
            ->withToken($token)
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($baseUrl ?? $this->url);
    }

    /**
     * Use CodexText handler which sends system prompts as top-level `instructions`
     * instead of merging them into the `input` array.
     */
    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new CodexText($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * Use CodexStream handler for proper streaming with Codex-formatted requests.
     */
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new CodexStream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }

    /**
     * Override error handling to parse Codex-specific error format and log details.
     */
    #[\Override]
    protected function handleResponseErrors(RequestException $e): never
    {
        $body = $e->response->body();
        $data = $e->response->json() ?? [];

        // Codex API may use a different error format than standard OpenAI
        $message = data_get($data, 'error.message')
            ?? data_get($data, 'detail')
            ?? data_get($data, 'message')
            ?? $body;

        if (is_array($message)) {
            $message = json_encode($message);
        }

        Log::warning('Codex API error', [
            'status' => $e->response->status(),
            'body' => Str::limit($body, 2000),
        ]);

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Codex',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.type') ?? data_get($data, 'error_code'),
            errorMessage: $message,
            previous: $e,
        );
    }
}
