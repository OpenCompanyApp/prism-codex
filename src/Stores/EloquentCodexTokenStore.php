<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Stores;

use OpenCompany\PrismCodex\Contracts\CodexTokenStore;
use OpenCompany\PrismCodex\CodexTokenStore as CodexTokenModel;
use OpenCompany\PrismCodex\ValueObjects\CodexToken;

final class EloquentCodexTokenStore implements CodexTokenStore
{
    public function current(): ?CodexToken
    {
        $model = CodexTokenModel::query()->latest()->first();

        if ($model === null) {
            return null;
        }

        return CodexToken::fromArray([
            'access_token' => $model->access_token,
            'refresh_token' => $model->refresh_token,
            'expires_at' => $model->expires_at,
            'account_id' => $model->account_id,
            'email' => $model->email,
            'token_data' => $model->token_data,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ]);
    }

    public function save(CodexToken $token): CodexToken
    {
        $data = [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'account_id' => $token->accountId,
            'email' => $token->email,
            'token_data' => $token->tokenData,
        ];

        $existing = CodexTokenModel::query()->first();

        if ($existing !== null) {
            $existing->update($data);

            return $this->current() ?? $token;
        }

        CodexTokenModel::query()->create($data);

        return $this->current() ?? $token;
    }

    public function clear(): void
    {
        CodexTokenModel::query()->delete();
    }
}
