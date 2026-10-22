<?php

declare(strict_types=1);

use Belvedere\Http\Controllers\BatchesController;
use Belvedere\Http\Controllers\DashboardController;
use Belvedere\Http\Controllers\FailedJobsController;
use Belvedere\Http\Controllers\HistoryController;
use Belvedere\Http\Controllers\MetricsController;
use Belvedere\Http\Controllers\MonitoringController;
use Belvedere\Http\Controllers\NativeJobsController;
use Belvedere\Http\Controllers\OverviewController;
use Belvedere\Http\Controllers\QueuesController;
use Belvedere\Http\Controllers\SupervisorsController;
use Belvedere\Http\Controllers\WorkersController;
use Belvedere\Http\Middleware\Authorize;
use Belvedere\Http\Middleware\EnforceReadOnly;
use Illuminate\Support\Facades\Route;

Route::group([
    'domain' => config('belvedere.domain'),
    'prefix' => config('belvedere.path', 'belvedere'),
    'middleware' => [...config('belvedere.middleware', ['web']), Authorize::class, EnforceReadOnly::class],
    'as' => 'belvedere.',
], static function (): void {
    Route::prefix('api')->as('api.')->group(static function (): void {
        Route::get('overview', OverviewController::class)->name('overview');

        Route::get('queues', [QueuesController::class, 'index'])->name('queues.index');
        Route::post('queues/{connection}/{queue}/pause', [QueuesController::class, 'pause'])->name('queues.pause');
        Route::post('queues/{connection}/{queue}/resume', [QueuesController::class, 'resume'])->name('queues.resume');
        Route::delete('queues/{connection}/{queue}', [QueuesController::class, 'purge'])->name('queues.purge');

        Route::get('jobs/failed', [FailedJobsController::class, 'index'])->name('failed.index');
        Route::post('jobs/failed/retry', [FailedJobsController::class, 'retryMany'])->name('failed.retry-many');
        Route::delete('jobs/failed', [FailedJobsController::class, 'flush'])->name('failed.flush');
        Route::get('jobs/failed/{id}', [FailedJobsController::class, 'show'])->name('failed.show');
        Route::post('jobs/failed/{id}/retry', [FailedJobsController::class, 'retry'])->name('failed.retry');
        Route::delete('jobs/failed/{id}', [FailedJobsController::class, 'destroy'])->name('failed.destroy');

        Route::get('jobs/history', [HistoryController::class, 'index'])->name('history.index');
        Route::get('jobs/history/{uuid}', [HistoryController::class, 'show'])->name('history.show');

        Route::get('jobs/{state}', [NativeJobsController::class, 'index'])
            ->whereIn('state', ['pending', 'delayed', 'running', 'stuck'])
            ->name('native.index');
        Route::delete('jobs/{connection}/{id}', [NativeJobsController::class, 'destroy'])->whereNumber('id')->name('native.destroy');
        Route::post('jobs/{connection}/{id}/run-now', [NativeJobsController::class, 'runNow'])->whereNumber('id')->name('native.run-now');
        Route::post('jobs/{connection}/{id}/release', [NativeJobsController::class, 'release'])->whereNumber('id')->name('native.release');

        Route::get('batches', [BatchesController::class, 'index'])->name('batches.index');
        Route::get('batches/{id}', [BatchesController::class, 'show'])->name('batches.show');
        Route::post('batches/{id}/cancel', [BatchesController::class, 'cancel'])->name('batches.cancel');
        Route::post('batches/{id}/retry', [BatchesController::class, 'retry'])->name('batches.retry');

        Route::get('workers', [WorkersController::class, 'index'])->name('workers.index');
        Route::delete('workers/{id}', [WorkersController::class, 'destroy'])->name('workers.destroy');

        Route::get('supervisors', [SupervisorsController::class, 'index'])->name('supervisors.index');
        Route::post('supervisors/{master}/pause', [SupervisorsController::class, 'pauseMaster'])->name('supervisors.pause');
        Route::post('supervisors/{master}/continue', [SupervisorsController::class, 'continueMaster'])->name('supervisors.continue');
        Route::post('supervisors/{master}/{supervisor}/pause', [SupervisorsController::class, 'pauseSupervisor'])->name('supervisors.pause-one');
        Route::post('supervisors/{master}/{supervisor}/continue', [SupervisorsController::class, 'continueSupervisor'])->name('supervisors.continue-one');

        Route::get('metrics/{type}', [MetricsController::class, 'index'])->whereIn('type', ['queue', 'job'])->name('metrics.index');
        Route::get('metrics/{type}/{name}', [MetricsController::class, 'show'])->whereIn('type', ['queue', 'job'])->name('metrics.show');

        Route::get('monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
        Route::post('monitoring', [MonitoringController::class, 'store'])->name('monitoring.store');
        Route::delete('monitoring/{tag}', [MonitoringController::class, 'destroy'])->name('monitoring.destroy');
        Route::get('monitoring/{tag}/jobs', [MonitoringController::class, 'jobs'])->name('monitoring.jobs');
    });

    Route::get('/{view?}', DashboardController::class)->where('view', '(.*)')->name('index');
});
