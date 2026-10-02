<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\Laravel\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

// Platform-wide: admin middleware does not exist yet. 'verified' matches other module patterns.
// TODO: create AdminMiddleware when platform admin role is introduced
Route::prefix('admin')->middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('audit', [AuditController::class, 'index'])
        ->name('audit.index');

    Route::get('audit/{id}', [AuditController::class, 'show'])
        ->name('audit.show');
});
