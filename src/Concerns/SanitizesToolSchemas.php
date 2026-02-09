<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Concerns;

/**
 * Sanitizes tool schemas for strict JSON Schema validation.
 *
 * The Codex API rejects array schemas without `items` definitions.
 * This trait recursively walks tool parameters and adds a default
 * `items: {type: string}` to any bare arrays.
 */
trait SanitizesToolSchemas
{
    /**
     * @param  array<int, mixed>  $tools
     * @return array<int, mixed>
     */
    protected function sanitizeTools(array $tools): array
    {
        return array_map(function (array $tool) {
            if (isset($tool['parameters']['properties'])) {
                $tool['parameters']['properties'] = $this->sanitizeProperties(
                    $tool['parameters']['properties']
                );
            }

            return $tool;
        }, $tools);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        foreach ($properties as $name => &$prop) {
            if (! is_array($prop)) {
                continue;
            }

            $prop = $this->sanitizeProperty($prop);
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @return array<string, mixed>
     */
    private function sanitizeProperty(array $prop): array
    {
        // Array without items → add default string items
        if (($prop['type'] ?? '') === 'array' && ! isset($prop['items'])) {
            $prop['items'] = ['type' => 'string'];
        }

        // Recurse into array items
        if (($prop['type'] ?? '') === 'array' && isset($prop['items']) && is_array($prop['items'])) {
            $prop['items'] = $this->sanitizeProperty($prop['items']);
        }

        // Recurse into object properties
        if (($prop['type'] ?? '') === 'object' && isset($prop['properties'])) {
            $prop['properties'] = $this->sanitizeProperties($prop['properties']);
        }

        return $prop;
    }
}
