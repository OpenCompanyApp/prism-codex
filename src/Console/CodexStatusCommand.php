<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Console;

use Illuminate\Console\Command;
use OpenCompany\PrismCodex\Contracts\CodexTokenStore;

class CodexStatusCommand extends Command
{
    protected $signature = 'codex:status';

    protected $description = 'Show Codex authentication status';

    public function handle(CodexTokenStore $tokens): int
    {
        $stored = $tokens->current();

        if (! $stored) {
            $this->warn('Codex is not configured. Run: php artisan codex:login');

            return self::SUCCESS;
        }

        $this->table(
            ['Property', 'Value'],
            [
                ['Status', $stored->isExpired() ? 'Expired' : 'Active'],
                ['Email', $stored->email ?? 'N/A'],
                ['Account ID', $stored->accountId ?? 'N/A'],
                ['Token Expires', $stored->expiresAt->format('Y-m-d H:i:s')],
                ['Valid', $stored->isExpiringSoon() ? 'Needs refresh' : 'Yes'],
                ['Last Updated', $stored->updatedAt?->format('Y-m-d H:i:s') ?? 'N/A'],
            ],
        );

        return self::SUCCESS;
    }
}
