<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex;

use Illuminate\Database\Eloquent\Model;

class CodexTokenStore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'token_data' => 'encrypted:array',
        ];
    }

    public function getTable(): string
    {
        return config('codex.table', 'codex_tokens');
    }

    public static function current(): ?self
    {
        return static::query()->latest()->first();
    }

    public static function store(array $data): self
    {
        // Upsert: single-row table pattern
        $existing = static::query()->first();

        if ($existing) {
            $existing->update($data);

            return $existing->fresh();
        }

        return static::create($data);
    }

    public static function clear(): void
    {
        static::query()->delete();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isExpiringSoon(int $bufferSeconds = 60): bool
    {
        return $this->expires_at->subSeconds($bufferSeconds)->isPast();
    }
}
