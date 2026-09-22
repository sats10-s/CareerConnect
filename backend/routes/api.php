<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Dashboard\AdminDashboardController;
use App\Http\Controllers\Api\Dashboard\FacultyDashboardController;
use App\Http\Controllers\Api\Dashboard\StudentDashboardController;
use App\Http\Controllers\Api\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Api\MetaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every route below is authenticated via Sanctum + gated by the `role`
| middleware where it matters. This is deliberate: the React app also
| hides nav it shouldn't show, but the *enforcement* happens here, not
| in the browser (see App\Http\Middleware\EnsureUserHasRole).
|
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::get('/meta/departments', [MetaController::class, 'departments']);
Route::get('/meta/batches', [MetaController::class, 'batches']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/student/dashboard', [StudentDashboardController::class, 'index'])
        ->middleware('role:STUDENT');

    Route::get('/faculty/dashboard', [FacultyDashboardController::class, 'index'])
        ->middleware('role:FACULTY');

    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->middleware('role:PLACEMENT_ADMIN,SUPER_ADMIN');

    Route::get('/super-admin/dashboard', [SuperAdminDashboardController::class, 'index'])
        ->middleware('role:SUPER_ADMIN');
});
