<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use QDenka\QueryDoctor\Http\Controllers\DoctorDashboardController;
use QDenka\QueryDoctor\Http\Middleware\QueryDoctorMiddleware;

$prefix = config('query-doctor.dashboard.prefix', 'query-doctor');

/** @var string[] $extraMiddleware */
$extraMiddleware = config('query-doctor.dashboard.middleware', []);
$middleware = array_merge(['web', QueryDoctorMiddleware::class], $extraMiddleware);

Route::prefix($prefix)
    ->middleware($middleware)
    ->name('query-doctor.')
    ->group(function (): void {
        Route::get('/', [DoctorDashboardController::class, 'index'])
            ->name('dashboard');

        Route::get('/api/issues', [DoctorDashboardController::class, 'apiIssues'])
            ->name('api.issues');

        Route::get('/api/queries', [DoctorDashboardController::class, 'apiQueries'])
            ->name('api.queries');

        Route::post('/api/baseline', [DoctorDashboardController::class, 'apiCreateBaseline'])
            ->name('api.baseline');

        Route::post('/api/ignore', [DoctorDashboardController::class, 'apiIgnoreIssue'])
            ->name('api.ignore');
    });
