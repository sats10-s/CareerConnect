<?php

use Illuminate\Support\Facades\Route;

// This is an API-first application (see routes/api.php). The React
// SPA is served separately by Vite/its own build - this route just
// confirms the backend is up when visited directly in a browser.
Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
        'api' => url('/api'),
    ]);
});
