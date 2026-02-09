<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex\Console;

use Illuminate\Console\Command;
use OpenCompany\PrismCodex\CodexTokenStore;

class CodexLogoutCommand extends Command
{
    protected $signature = 'codex:logout';

    protected $description = 'Remove stored Codex authentication tokens';

    public function handle(): int
    {
        $stored = CodexTokenStore::current();

        if (! $stored) {
            $this->info('No Codex tokens stored.');

            return self::SUCCESS;
        }

        CodexTokenStore::clear();
        $this->info('Codex tokens removed.');

        return self::SUCCESS;
    }
}
