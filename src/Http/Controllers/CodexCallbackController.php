<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use OpenCompany\PrismCodex\CodexOAuthService;

class CodexCallbackController extends Controller
{
    public function handle(Request $request, CodexOAuthService $oauthService)
    {
        $state = $request->query('state');
        $code = $request->query('code');
        $error = $request->query('error');

        if ($error) {
            return response()->json([
                'error' => $error,
                'description' => $request->query('error_description', 'Authentication failed'),
            ], 400);
        }

        if (! $state || ! $code) {
            return response()->json(['error' => 'Missing state or code parameter'], 400);
        }

        // Validate state from cache
        $cached = Cache::pull("codex-oauth-state:{$state}");

        if (! $cached) {
            return response()->json(['error' => 'Invalid or expired state parameter'], 400);
        }

        try {
            $redirectUri = $cached['redirect_uri'] ?? route('codex.callback');
            $tokens = $oauthService->exchangeCode($code, $cached['verifier'], $redirectUri);
            $oauthService->storeTokens($tokens);

            return redirect($cached['return_url'] ?? '/')
                ->with('success', 'Codex authentication successful!');
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'token_exchange_failed',
                'description' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Start a web-based OAuth flow by redirecting to OpenAI.
     */
    public function redirect(CodexOAuthService $oauthService, Request $request)
    {
        $pkce = $oauthService->generatePkce();
        $state = bin2hex(random_bytes(16));
        $redirectUri = route('codex.callback');

        // Store PKCE verifier in cache (5 min TTL)
        Cache::put("codex-oauth-state:{$state}", [
            'verifier' => $pkce['verifier'],
            'redirect_uri' => $redirectUri,
            'return_url' => $request->query('return_url', '/'),
        ], 300);

        $authUrl = $oauthService->buildAuthorizationUrl($pkce['challenge'], $state, $redirectUri);

        return redirect($authUrl);
    }
}
