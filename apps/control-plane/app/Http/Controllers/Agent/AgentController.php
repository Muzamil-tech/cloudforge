<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Models\Server;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Http\Request;

abstract class AgentController extends Controller
{
    protected function agentServer(Request $request): Server
    {
        /** @var Server|null $server */
        $server = $request->attributes->get(AuthenticateAgent::SERVER_ATTRIBUTE);

        abort_if($server === null, 401, 'The agent request is not authenticated.');

        return $server;
    }
}
