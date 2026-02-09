<?php

use Illuminate\Support\Facades\Route;
use OpenCompany\PrismCodex\Http\Controllers\CodexCallbackController;

Route::middleware('web')->group(function () {
    Route::get(config('codex.callback_route', '/auth/codex/callback'), [CodexCallbackController::class, 'handle'])
        ->name('codex.callback');

    Route::get('/auth/codex/redirect', [CodexCallbackController::class, 'redirect'])
        ->name('codex.redirect');
});
