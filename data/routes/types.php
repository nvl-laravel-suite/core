<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Data\Http\Controllers\GeneratedTypesController;
use Nvl\Data\Services\GeneratedTypesRouteConfiguration;

$routeConfiguration = app(GeneratedTypesRouteConfiguration::class);

Route::middleware($routeConfiguration->middleware())
    ->prefix($routeConfiguration->prefix())
    ->name('nvl-data.types.')
    ->group(function (): void {
        Route::get('/', [GeneratedTypesController::class, 'index'])->name('index');
        Route::get('/entrypoint', [GeneratedTypesController::class, 'entrypoint'])->name('entrypoint');
        Route::get('/archive', [GeneratedTypesController::class, 'archive'])->name('archive');
        Route::get('/{scope}', [GeneratedTypesController::class, 'show'])
            ->where('scope', '[A-Za-z0-9_-]+')
            ->name('show');
    });
