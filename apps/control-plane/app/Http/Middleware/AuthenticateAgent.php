<?php

namespace App\Http\Middleware;

use App\Domains\Infrastructure\Services\AgentRequestVerifier;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public const CERTIFICATE_ATTRIBUTE = 'cloudforge.agent.certificate';

    public const SERVER_ATTRIBUTE = 'cloudforge.agent.server';

    public function __construct(private readonly AgentRequestVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $certificate = $this->verifier->verify($request);
        } catch (AuthenticationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'agent_authentication_failed',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::CERTIFICATE_ATTRIBUTE, $certificate);
        $request->attributes->set(self::SERVER_ATTRIBUTE, $certificate->server);

        return $next($request);
    }
}
