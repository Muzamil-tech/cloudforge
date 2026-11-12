<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Services\RecordInventory;
use App\Http\Requests\Agent\InventoryRequest;
use Illuminate\Http\JsonResponse;

class InventoryController extends AgentController
{
    public function store(InventoryRequest $request, RecordInventory $recordInventory): JsonResponse
    {
        $server = $recordInventory->execute($this->agentServer($request), $request->validated());

        return response()->json([
            'status' => 'ok',
            'server_status' => $server->status->value,
            'inventory_recorded_at' => $server->last_inventory_at?->toIso8601String(),
        ]);
    }
}
