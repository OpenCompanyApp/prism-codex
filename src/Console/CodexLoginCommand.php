<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Console;

use Illuminate\Console\Command;
use OpenCompany\PrismCodex\CodexOAuthService;

class CodexLoginCommand extends Command
{
    protected $signature = 'codex:login {--device : Use device authorization flow (headless)}';

    protected $description = 'Authenticate with your ChatGPT subscription for Codex API access';

    public function handle(CodexOAuthService $oauthService): int
    {
        if ($this->option('device')) {
            return $this->deviceFlow($oauthService);
        }

        return $this->browserFlow($oauthService);
    }

    protected function browserFlow(CodexOAuthService $oauthService): int
    {
        $pkce = $oauthService->generatePkce();
        $state = bin2hex(random_bytes(16));
        $port = (int) config('codex.oauth_port', 9876);
        $redirectUri = "http://127.0.0.1:{$port}/auth/callback";

        $authUrl = $oauthService->buildAuthorizationUrl($pkce['challenge'], $state, $redirectUri);

        $this->info('Opening browser for ChatGPT login...');
        $this->line("If the browser doesn't open, visit:");
        $this->newLine();
        $this->line("  <href={$authUrl}>{$authUrl}</>");
        $this->newLine();

        // Open browser
        $openCommand = PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open';
        exec("{$openCommand} ".escapeshellarg($authUrl).' 2>/dev/null &');

        // Start local callback server
        $this->info("Waiting for callback on port {$port}...");

        $code = $this->waitForCallback($port, $state);

        if (! $code) {
            $this->error('Authentication failed or timed out.');

            return self::FAILURE;
        }

        $this->info('Exchanging authorization code for tokens...');

        try {
            $tokens = $oauthService->exchangeCode($code, $pkce['verifier'], $redirectUri);
            $oauthService->storeTokens($tokens);

            $email = $oauthService->extractEmailFromJwt($tokens['id_token'] ?? '')
                ?? $oauthService->extractEmailFromJwt($tokens['access_token']);

            $this->info('Authenticated successfully!');
            if ($email) {
                $this->line("  Account: {$email}");
            }

            $accountId = $oauthService->getAccountId();
            if ($accountId) {
                $this->line("  Account ID: {$accountId}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Token exchange failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function deviceFlow(CodexOAuthService $oauthService): int
    {
        $this->info('Initiating device authorization flow...');

        try {
            $device = $oauthService->initiateDeviceAuth();
        } catch (\Throwable $e) {
            $this->error('Failed to initiate device auth: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Your code: <options=bold>'.$device['user_code'].'</>');
        $this->newLine();
        $this->line('  Visit: <href=https://auth.openai.com/codex/device>https://auth.openai.com/codex/device</>');
        $this->line('  Enter the code above and authorize access.');
        $this->newLine();

        $interval = $device['interval'] + 3; // Safety margin
        $maxAttempts = (int) ceil(300 / $interval); // 5 minute timeout

        $this->info('Polling for authorization...');

        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep($interval);

            try {
                $tokens = $oauthService->pollDeviceAuth(
                    $device['device_auth_id'],
                    $device['user_code'],
                );

                if ($tokens === null) {
                    $this->output->write('.');

                    continue;
                }

                $oauthService->storeTokens($tokens);
                $this->newLine();
                $this->info('Authenticated successfully!');

                $email = $oauthService->extractEmailFromJwt($tokens['id_token'] ?? '')
                    ?? $oauthService->extractEmailFromJwt($tokens['access_token'] ?? '');
                if ($email) {
                    $this->line("  Account: {$email}");
                }

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error('Device auth failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->error('Timed out waiting for authorization.');

        return self::FAILURE;
    }

    /**
     * Start a temporary TCP server to receive the OAuth callback.
     */
    protected function waitForCallback(int $port, string $expectedState): ?string
    {
        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        if (! $server) {
            $this->error("Could not start callback server on port {$port}: {$errstr}");

            return null;
        }

        // 2 minute timeout
        stream_set_timeout($server, 120);

        $client = @stream_socket_accept($server, 120);

        if (! $client) {
            fclose($server);

            return null;
        }

        $request = fread($client, 8192);
        fclose($server);

        if (! $request) {
            fclose($client);

            return null;
        }

        // Parse the GET request
        preg_match('/GET\s+([^\s]+)/', $request, $matches);
        $path = $matches[1] ?? '';

        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $params);

        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if ($state !== $expectedState) {
            $response = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication failed</h2><p>Invalid state parameter.</p></body></html>";
            fwrite($client, $response);
            fclose($client);

            return null;
        }

        if (! $code) {
            $error = $params['error'] ?? 'unknown_error';
            $response = "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication failed</h2><p>Error: {$error}</p></body></html>";
            fwrite($client, $response);
            fclose($client);

            return null;
        }

        $response = "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body><h2>Authentication successful!</h2><p>You can close this window and return to the terminal.</p></body></html>";
        fwrite($client, $response);
        fclose($client);

        return $code;
    }
}
