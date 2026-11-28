<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AdminAnalystController;
use App\Http\Controllers\Api\AdminOwnershipTransferController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EvaluationController;
use App\Http\Controllers\Api\IntelligenceArtifactController;
use App\Http\Controllers\Api\IntelligenceArtifactDownloadController;
use App\Http\Controllers\Api\IntelligenceCallbackController;
use App\Http\Controllers\Api\IntelligenceDeliveryStatusController;
use App\Http\Controllers\Api\IntelligenceRecipientController;
use App\Http\Controllers\Api\IntelligenceReportController;
use App\Http\Controllers\Api\IntelligenceSnapshotController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\QuestionnaireController;
use App\Http\Controllers\Api\SixFactorEvaluationController;
use App\Http\Controllers\Api\SixFactorMonitoringController;
use App\Http\Controllers\Api\SixFactorPredictionController;
use App\Http\Controllers\Api\UmkmController;
use App\Http\Middleware\ApplyAnalystFilter;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureSuperadmin;
use App\Http\Middleware\VerifyIntelligenceArtifactSignature;
use App\Http\Middleware\VerifyIntelligenceCallbackSignature;
use App\Http\Middleware\VerifyIntelligenceDeliverySignature;
use App\Http\Middleware\VerifyIntelligenceRecipientSignature;
use App\Http\Middleware\VerifyIntelligenceSnapshotSignature;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:joyumkm-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:joyumkm-login');

// Callback server-ke-server dari Hermes: tanpa Sanctum, dilindungi HMAC khusus callback.
Route::post('/internal/intelligence/callback', [IntelligenceCallbackController::class, 'store'])
    ->middleware(VerifyIntelligenceCallbackSignature::class);

Route::post('/internal/intelligence/deliveries', [IntelligenceDeliveryStatusController::class, 'store'])
    ->middleware(['throttle:60,1,delivery-status', VerifyIntelligenceDeliverySignature::class]);
Route::options('/internal/intelligence/deliveries', fn () => abort(405, '', ['Allow' => 'POST']));

Route::get('/internal/intelligence/recipients/{eventId}', [IntelligenceRecipientController::class, 'show'])
    ->where('eventId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
    ->middleware(['throttle:joyumkm-intelligence-recipient', VerifyIntelligenceRecipientSignature::class]);
Route::options('/internal/intelligence/recipients/{eventId}', fn () => abort(405, '', ['Allow' => 'GET']));

// Pull snapshot historis P3-01: GET read-only, ditandatangani secret snapshot terpisah, terikat pada satu event.
Route::get('/internal/intelligence/events/{eventId}/snapshot', [IntelligenceSnapshotController::class, 'show'])
    ->where('eventId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
    ->middleware(VerifyIntelligenceSnapshotSignature::class);

// Pemantauan periodik Jobsheet 5: GET read-only total skor final Enam Faktor. Ditandatangani secret snapshot yang sama.
Route::get('/internal/monitoring/six-factor/latest-finals', SixFactorMonitoringController::class)
    ->middleware(VerifyIntelligenceSnapshotSignature::class)
    ->name('internal.monitoring.six-factor.latest-finals');

// Unggah artefak P4-01: PUT biner, HMAC khusus artefak terikat method/path/event/kind/digest. Tanpa Sanctum.
Route::put('/internal/intelligence/artifacts/{eventId}/{kind}', [IntelligenceArtifactController::class, 'update'])
    ->where('eventId', '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}')
    ->where('kind', 'pdf|xlsx')
    ->middleware(['throttle:joyumkm-intelligence-artifact', VerifyIntelligenceArtifactSignature::class])
    ->name('internal.intelligence.artifacts.upload');

Route::middleware(['auth:sanctum', EnsureAccountActive::class, ApplyAnalystFilter::class])->group(function (): void {
    Route::prefix('admin')->middleware(EnsureSuperadmin::class)->group(function (): void {
        Route::get('/analysts', [AdminAnalystController::class, 'index']);
        Route::post('/analysts', [AdminAnalystController::class, 'store']);
        Route::get('/analysts/{id}', [AdminAnalystController::class, 'show'])->whereNumber('id');
        Route::patch('/analysts/{id}', [AdminAnalystController::class, 'update'])->whereNumber('id');
        Route::patch('/analysts/{id}/status', [AdminAnalystController::class, 'status'])->whereNumber('id');
        Route::post('/umkms/{id}/transfer', [AdminOwnershipTransferController::class, 'store'])->whereNumber('id');
        Route::get('/ownership-transfers', [AdminOwnershipTransferController::class, 'index']);
    });
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard/factor-profile', [DashboardController::class, 'factorProfile']);
    Route::get('/dashboard/distribution', [DashboardController::class, 'distribution']);
    Route::get('/dashboard/comparison', [DashboardController::class, 'comparison']);
    Route::get('/dashboard/three-dimension-health', [DashboardController::class, 'threeDimensionHealth']);
    Route::get('/dashboard/three-dimension-detail', [DashboardController::class, 'threeDimensionDetail']);
    Route::get('/dashboard/six-factor-summary', [DashboardController::class, 'sixFactorSummary']);
    Route::get('/questionnaire', [QuestionnaireController::class, 'show']);
    Route::prefix('locations')->middleware('throttle:joyumkm-geocoding')->group(function (): void {
        Route::post('/search', [LocationController::class, 'search']);
        Route::post('/reverse', [LocationController::class, 'reverse']);
    });
    Route::get('/umkms/{id}/evaluations/latest', [EvaluationController::class, 'latest'])->whereNumber('id');
    Route::post('/umkms/{id}/evaluations', [EvaluationController::class, 'store'])->whereNumber('id');
    Route::post('/umkms/{id}/six-factor-evaluations/predict', SixFactorPredictionController::class)
        ->whereNumber('id')->name('umkms.six-factor-evaluations.predict');
    Route::prefix('/umkms/{id}/six-factor-evaluations')
        ->where(['id' => '[0-9]+', 'evaluationId' => '[0-9]+'])
        ->controller(SixFactorEvaluationController::class)
        ->group(function (): void {
            Route::get('/draft', 'draft');
            Route::match(['put', 'patch'], '/draft', 'save');
            Route::post('/{evaluationId}/finalize', 'finalize');
            Route::post('/{evaluationId}/cancel', 'cancel');
            Route::get('/', 'history');
            Route::get('/latest', 'latest');
        });
    Route::prefix('/umkms/{id}/intelligence-reports')
        ->where(['id' => '[0-9]+', 'reportId' => '[0-9]+'])
        ->controller(IntelligenceReportController::class)
        ->group(function (): void {
            Route::get('/latest', 'latest');
            Route::get('/', 'index');
            Route::get('/{reportId}', 'show');
        });
    Route::get('/umkms/{id}/intelligence-reports/{reportId}/artifacts', [IntelligenceArtifactDownloadController::class, 'index'])
        ->whereNumber('id')->whereNumber('reportId');
    Route::get('/umkms/{id}/intelligence-reports/{reportId}/artifacts/{kind}', [IntelligenceArtifactDownloadController::class, 'show'])
        ->whereNumber('id')->whereNumber('reportId')->where('kind', 'pdf|xlsx')->name('intelligence.artifacts.download');
    Route::get('/umkms/options', [UmkmController::class, 'options']);
    Route::get('/umkms/summary', [UmkmController::class, 'summary']);
    Route::post('/umkms/whatsapp-check', [UmkmController::class, 'whatsappCheck'])
        ->middleware('throttle:joyumkm-whatsapp-check');
    Route::post('/umkms/{id}/whatsapp-check', [UmkmController::class, 'recheckWhatsapp'])
        ->whereNumber('id')
        ->middleware('throttle:joyumkm-whatsapp-check');
    Route::get('/umkms', [UmkmController::class, 'index']);
    Route::post('/umkms', [UmkmController::class, 'store']);
    Route::get('/umkms/{id}', [UmkmController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/umkms/{id}', [UmkmController::class, 'update'])->whereNumber('id');
    Route::delete('/umkms/{id}', [UmkmController::class, 'destroy'])->whereNumber('id');
});
