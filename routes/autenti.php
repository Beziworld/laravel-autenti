<?php

declare(strict_types=1);

use Beziworld\Autenti\Http\Controllers\CallbackController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$middleware = Config::get('autenti.callbacks.middleware', []);
$path = trim((string) Config::get('autenti.callbacks.path', 'autenti/callback'), '/');

Route::post($path . '/{connection?}', CallbackController::class)
    ->middleware(is_array($middleware) ? $middleware : [])
    ->where('connection', '[A-Za-z0-9_.\-]+')
    ->name((string) Config::get('autenti.callbacks.name', 'autenti.callback'));
