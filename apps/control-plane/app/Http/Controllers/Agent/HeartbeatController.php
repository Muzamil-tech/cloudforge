<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Services\RecordHeartbeat;
use App\Http\Requests\Agent\HeartbeatRequest;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends AgentController
{
    public function store(HeartbeatRequest $request, RecordHeartbeat $recordHeartbeat): JsonResponse
    {
        $server = $recordHeartbeat->execute($this->agentServer($request), $request->validated());

        return response()->json([
            'status' => 'ok',
            'server_status' => $server->status->value,
            'heartbeat_interval_seconds' => 30,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
