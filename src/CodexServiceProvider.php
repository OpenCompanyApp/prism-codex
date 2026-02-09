<?php

declare(strict_types=1);

namespace OpenCompany\PrismCodex;

use Illuminate\Support\ServiceProvider;
use OpenCompany\PrismCodex\Console\CodexLoginCommand;
use OpenCompany\PrismCodex\Console\CodexLogoutCommand;
use OpenCompany\PrismCodex\Console\CodexStatusCommand;
use Prism\Prism\PrismManager;

class CodexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/codex.php', 'codex');

        $this->app->singleton(CodexTokenStore::class);
        $this->app->singleton(CodexOAuthService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/codex.php' => config_path('codex.php'),
        ], 'codex-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'codex-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CodexLoginCommand::class,
                CodexStatusCommand::class,
                CodexLogoutCommand::class,
            ]);
        }

        $this->app->afterResolving(PrismManager::class, function (PrismManager $manager): void {
            $manager->extend('codex', function ($app, array $config) {
                $oauthService = $app->make(CodexOAuthService::class);

                return new Codex(
                    oauthService: $oauthService,
                    url: $config['url'] ?? config('codex.url', 'https://chatgpt.com/backend-api/codex'),
                    accountId: $config['account_id'] ?? null,
                );
            });
        });
    }
}
