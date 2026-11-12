<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Services\RecordMetrics;
use App\Http\Requests\Agent\MetricsRequest;
use Illuminate\Http\JsonResponse;

class MetricsController extends AgentController
{
    public function store(MetricsRequest $request, RecordMetrics $recordMetrics): JsonResponse
    {
        $count = $recordMetrics->execute($this->agentServer($request), $request->validated('points'));

        return response()->json(['status' => 'ok', 'recorded' => $count]);
    }
}
