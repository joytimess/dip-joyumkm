<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\QuestionnaireController;
use App\Http\Controllers\Api\UmkmController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:joyumkm-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:joyumkm-login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard/factor-profile', [DashboardController::class, 'factorProfile']);
    Route::get('/dashboard/distribution', [DashboardController::class, 'distribution']);
    Route::get('/dashboard/comparison', [DashboardController::class, 'comparison']);
    Route::get('/questionnaire', [QuestionnaireController::class, 'show']);
    Route::get('/umkms/{id}/evaluations/latest', [EvaluationController::class, 'latest'])->whereNumber('id');
    Route::post('/umkms/{id}/evaluations', [EvaluationController::class, 'store'])->whereNumber('id');
    Route::get('/umkms/options', [UmkmController::class, 'options']);
    Route::get('/umkms/summary', [UmkmController::class, 'summary']);
    Route::get('/umkms', [UmkmController::class, 'index']);
    Route::post('/umkms', [UmkmController::class, 'store']);
    Route::get('/umkms/{id}', [UmkmController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/umkms/{id}', [UmkmController::class, 'update'])->whereNumber('id');
    Route::delete('/umkms/{id}', [UmkmController::class, 'destroy'])->whereNumber('id');
});
