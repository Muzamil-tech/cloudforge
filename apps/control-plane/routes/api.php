<?php

use App\Http\Controllers\Agent\CommandController as AgentCommandController;
use App\Http\Controllers\Agent\EnrollmentController;
use App\Http\Controllers\Agent\HeartbeatController;
use App\Http\Controllers\Agent\InventoryController;
use App\Http\Controllers\Agent\MetricsController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ServerController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent/v1')->group(function (): void {
    Route::post('enroll', [EnrollmentController::class, 'store'])
        ->middleware('throttle:agent-enroll')
        ->name('agent.enroll');

    Route::middleware(['throttle:agent', 'auth.agent'])->group(function (): void {
        Route::post('heartbeat', [HeartbeatController::class, 'store'])->name('agent.heartbeat');
        Route::post('inventory', [InventoryController::class, 'store'])->name('agent.inventory');
        Route::post('metrics', [MetricsController::class, 'store'])->name('agent.metrics');
        Route::post('certificates/rotate', [AgentCommandController::class, 'rotateCertificate'])
            ->name('agent.certificates.rotate');
        Route::get('commands/next', [AgentCommandController::class, 'next'])->name('agent.commands.next');
        Route::post('commands/{command}/ack', [AgentCommandController::class, 'acknowledge'])
            ->name('agent.commands.acknowledge');
        Route::post('commands/{command}/result', [AgentCommandController::class, 'result'])
            ->name('agent.commands.result');
    });
});

Route::prefix('v1')->middleware(['auth.org-token', 'throttle:api'])->group(function (): void {
    Route::get('me', [MeController::class, 'show'])->name('api.me');

    Route::middleware('token.ability:servers:read')->group(function (): void {
        Route::get('servers', [ServerController::class, 'index'])->name('api.servers.index');
        Route::get('servers/{server}', [ServerController::class, 'show'])->name('api.servers.show');
        Route::get('servers/{server}/commands', [ServerController::class, 'commands'])->name('api.servers.commands');
    });

    Route::middleware('token.ability:servers:write')->group(function (): void {
        Route::post('servers', [ServerController::class, 'store'])->name('api.servers.store');
        Route::patch('servers/{server}', [ServerController::class, 'update'])->name('api.servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('api.servers.destroy');
        Route::post('servers/{server}/enrollment', [ServerController::class, 'issueEnrollment'])->name('api.servers.enrollment');
        Route::post('servers/{server}/certificates/revoke', [ServerController::class, 'revokeCertificates'])->name('api.servers.certificates.revoke');
    });

    Route::middleware('token.ability:commands:write')->group(function (): void {
        Route::post('servers/{server}/commands', [ServerController::class, 'issueCommand'])->name('api.servers.commands.store');
        Route::post('servers/{server}/commands/{command}/cancel', [ServerController::class, 'cancelCommand'])->name('api.servers.commands.cancel');
    });

    Route::middleware('token.ability:projects:read')->group(function (): void {
        Route::get('projects', [ProjectController::class, 'index'])->name('api.projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->name('api.projects.show');
    });

    Route::middleware('token.ability:projects:write')->group(function (): void {
        Route::post('projects', [ProjectController::class, 'store'])->name('api.projects.store');
        Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('api.projects.update');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('api.projects.destroy');
        Route::post('projects/{project}/environments', [ProjectController::class, 'storeEnvironment'])->name('api.projects.environments.store');
    });

    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('token.ability:audit:read')
        ->name('api.audit-logs.index');
});
