<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\Laravel\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['web', 'auth'])->group(function () {
    Route::get('audit', [AuditController::class, 'index'])
        ->name('audit.index');

    Route::get('audit/{id}', [AuditController::class, 'show'])
        ->name('audit.show');
});
