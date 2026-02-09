<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Handlers;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use OpenCompany\PrismCodex\Concerns\SanitizesToolSchemas;
use Prism\Prism\Providers\OpenAI\Handlers\Text;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;

/**
 * Codex-specific text handler.
 *
 * The Codex API at chatgpt.com/backend-api/codex/responses requires:
 * - Top-level `instructions` field (system prompt) — NOT in the `input` array
 * - `stream: true` (streaming is mandatory)
 * - `store: false` (no server-side storage)
 * - Only user/assistant/tool messages in `input`
 *
 * This handler sends a streaming request, collects the full SSE response,
 * extracts the `response.completed` event, and returns a synthetic
 * ClientResponse so the parent Text handler processes it as normal JSON.
 */
class CodexText extends Text
{
    use SanitizesToolSchemas;
    #[\Override]
    protected function sendRequest(Request $request): ClientResponse
    {
        $instructions = collect($request->systemPrompts())
            ->map(fn ($msg) => $msg->content)
            ->implode("\n\n");

        // Build input WITHOUT system prompts — Codex rejects them in input
        $input = (new MessageMap($request->messages(), []))();

        // Codex API requires streaming — we buffer the full response and parse SSE
        /** @var ClientResponse $response */
        $response = $this->client
            ->timeout(300)
            ->post(
                'responses',
                array_merge([
                    'model' => $request->model(),
                    'instructions' => $instructions ?: 'You are a helpful assistant.',
                    'input' => $input,
                    'stream' => true,
                    'store' => false,
                ], Arr::whereNotNull([
                    'max_output_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => $this->sanitizeTools($this->buildTools($request)),
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    'reasoning' => $request->providerOptions('reasoning'),
                ]))
            );

        return $this->collectStreamResponse($response);
    }

    /**
     * Parse the buffered SSE body and extract the response.completed event.
     * Returns a synthetic ClientResponse with the full response JSON so the
     * parent Text handler can process it as a normal non-streaming response.
     */
    private function collectStreamResponse(ClientResponse $response): ClientResponse
    {
        $body = $response->body();
        $completedData = null;

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $jsonStr = trim(substr($line, 5));

            if ($jsonStr === '' || $jsonStr === '[DONE]') {
                continue;
            }

            $data = json_decode($jsonStr, true);

            if (! $data) {
                continue;
            }

            // Capture the final complete response
            if (($data['type'] ?? '') === 'response.completed') {
                $completedData = $data['response'] ?? $data;
            }
        }

        if ($completedData) {
            return new ClientResponse(new Psr7Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($completedData)
            ));
        }

        // No response.completed found — return original (will likely fail validation)
        return $response;
    }
}
