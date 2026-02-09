<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Console;

use Illuminate\Console\Command;
use OpenCompany\PrismCodex\CodexOAuthService;
use OpenCompany\PrismCodex\CodexTokenStore;

class CodexStatusCommand extends Command
{
    protected $signature = 'codex:status';

    protected $description = 'Show Codex authentication status';

    public function handle(CodexOAuthService $oauthService): int
    {
        $stored = CodexTokenStore::current();

        if (! $stored) {
            $this->warn('Codex is not configured. Run: php artisan codex:login');

            return self::SUCCESS;
        }

        $this->table(
            ['Property', 'Value'],
            [
                ['Status', $stored->isExpired() ? 'Expired' : 'Active'],
                ['Email', $stored->email ?? 'N/A'],
                ['Account ID', $stored->account_id ?? 'N/A'],
                ['Token Expires', $stored->expires_at->toDateTimeString()],
                ['Valid', $stored->isExpiringSoon() ? 'Needs refresh' : 'Yes'],
                ['Last Updated', $stored->updated_at?->toDateTimeString() ?? 'N/A'],
            ],
        );

        return self::SUCCESS;
    }
}
