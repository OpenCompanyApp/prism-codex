<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CodexOAuthService
{
    public const ISSUER = 'https://auth.openai.com';

    public const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';

    public const SCOPES = 'openid profile email offline_access';

    public const DEVICE_AUTH_REDIRECT_URI = 'https://auth.openai.com/deviceauth/callback';

    /**
     * Generate PKCE code verifier and challenge.
     *
     * @return array{verifier: string, challenge: string}
     */
    public function generatePkce(): array
    {
        $verifier = Str::random(43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'verifier' => $verifier,
            'challenge' => $challenge,
        ];
    }

    /**
     * Build the authorization URL for browser PKCE flow.
     */
    public function buildAuthorizationUrl(string $challenge, string $state, string $redirectUri): string
    {
        return self::ISSUER.'/oauth/authorize?'.http_build_query([
            'client_id' => self::CLIENT_ID,
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'codex_cli_simplified_flow' => 'true',
        ]);
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, id_token?: string}
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::ISSUER.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Initiate device authorization flow.
     *
     * @return array{device_auth_id: string, user_code: string, interval: int}
     */
    public function initiateDeviceAuth(): array
    {
        $response = Http::asJson()->post(self::ISSUER.'/api/accounts/deviceauth/usercode', [
            'client_id' => self::CLIENT_ID,
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Poll device authorization status.
     *
     * @return array|null Returns token data on success, null if still pending.
     */
    public function pollDeviceAuth(string $deviceAuthId, string $userCode): ?array
    {
        $response = Http::asJson()->post(self::ISSUER.'/api/accounts/deviceauth/token', [
            'device_auth_id' => $deviceAuthId,
            'user_code' => $userCode,
        ]);

        // 403 means still pending
        if ($response->status() === 403) {
            return null;
        }

        $response->throw();

        $data = $response->json();

        // Exchange the device auth code for tokens
        if (isset($data['authorization_code'], $data['code_verifier'])) {
            return $this->exchangeCode(
                $data['authorization_code'],
                $data['code_verifier'],
                self::DEVICE_AUTH_REDIRECT_URI,
            );
        }

        return $data;
    }

    /**
     * Refresh the access token using the stored refresh token.
     */
    public function refreshToken(): bool
    {
        $stored = CodexTokenStore::current();

        if (! $stored) {
            return false;
        }

        $response = Http::asForm()->post(self::ISSUER.'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $stored->refresh_token,
            'client_id' => self::CLIENT_ID,
        ]);

        if ($response->failed()) {
            return false;
        }

        $data = $response->json();

        $accountId = $this->extractAccountIdFromJwt($data['access_token'] ?? '')
            ?? $this->extractAccountIdFromJwt($data['id_token'] ?? '')
            ?? $stored->account_id;

        $this->storeTokens([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $stored->refresh_token,
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            'account_id' => $accountId,
        ]);

        return true;
    }

    /**
     * Get a valid access token, auto-refreshing if needed.
     */
    public function getAccessToken(): ?string
    {
        $stored = CodexTokenStore::current();

        if (! $stored) {
            return null;
        }

        if ($stored->isExpiringSoon()) {
            if (! $this->refreshToken()) {
                return null;
            }
            $stored = CodexTokenStore::current();
        }

        return $stored?->access_token;
    }

    /**
     * Get the stored ChatGPT account ID.
     */
    public function getAccountId(): ?string
    {
        return CodexTokenStore::current()?->account_id;
    }

    /**
     * Check if Codex OAuth is configured with stored tokens.
     */
    public function isConfigured(): bool
    {
        return CodexTokenStore::current() !== null;
    }

    /**
     * Store token data from an OAuth exchange.
     */
    public function storeTokens(array $data): void
    {
        $accessToken = $data['access_token'];

        $accountId = $data['account_id']
            ?? $this->extractAccountIdFromJwt($accessToken)
            ?? $this->extractAccountIdFromJwt($data['id_token'] ?? '');

        $email = $this->extractEmailFromJwt($data['id_token'] ?? '')
            ?? $this->extractEmailFromJwt($accessToken);

        CodexTokenStore::store([
            'access_token' => $accessToken,
            'refresh_token' => $data['refresh_token'],
            'expires_at' => $data['expires_at'] ?? now()->addSeconds($data['expires_in'] ?? 3600),
            'account_id' => $accountId,
            'email' => $email,
            'token_data' => array_filter([
                'id_token' => $data['id_token'] ?? null,
            ]),
        ]);
    }

    /**
     * Extract ChatGPT account ID from a JWT token.
     * Searches claims in priority order: root > namespaced > org array.
     */
    public function extractAccountIdFromJwt(string $jwt): ?string
    {
        $claims = $this->decodeJwtClaims($jwt);

        if (! $claims) {
            return null;
        }

        // Priority 1: Root-level claim
        if (! empty($claims['chatgpt_account_id'])) {
            return $claims['chatgpt_account_id'];
        }

        // Priority 2: Namespaced claim
        $namespaced = $claims['https://api.openai.com/auth'] ?? null;
        if (is_array($namespaced) && ! empty($namespaced['chatgpt_account_id'])) {
            return $namespaced['chatgpt_account_id'];
        }

        // Priority 3: Organizations array
        $orgs = $claims['organizations'] ?? null;
        if (is_array($orgs) && ! empty($orgs[0]['id'])) {
            return $orgs[0]['id'];
        }

        return null;
    }

    /**
     * Extract email from a JWT token.
     */
    public function extractEmailFromJwt(string $jwt): ?string
    {
        $claims = $this->decodeJwtClaims($jwt);

        return $claims['email'] ?? null;
    }

    /**
     * Decode JWT payload claims without signature verification.
     * (We trust tokens from auth.openai.com over HTTPS.)
     */
    protected function decodeJwtClaims(string $jwt): ?array
    {
        if (empty($jwt)) {
            return null;
        }

        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'));
        $claims = json_decode($payload, true);

        return is_array($claims) ? $claims : null;
    }
}
