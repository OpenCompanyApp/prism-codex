<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Handlers;

use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Arr;
use OpenCompany\PrismCodex\Concerns\SanitizesToolSchemas;
use Prism\Prism\Providers\OpenAI\Handlers\Stream;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolChoiceMap;
use Prism\Prism\Text\Request;

/**
 * Codex-specific stream handler.
 *
 * Same Codex API adaptations as CodexText:
 * - Top-level `instructions` field
 * - `store: false`
 * - No system messages in `input`
 */
class CodexStream extends Stream
{
    use SanitizesToolSchemas;
    #[\Override]
    protected function sendRequest(Request $request): ClientResponse
    {
        $instructions = collect($request->systemPrompts())
            ->map(fn ($msg) => $msg->content)
            ->implode("\n\n");

        $input = (new MessageMap($request->messages(), []))();

        /** @var ClientResponse $response */
        $response = $this->client
            ->timeout(300)
            ->withOptions(['stream' => true])
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

        return $response;
    }
}
