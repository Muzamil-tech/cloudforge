<?php

namespace App\Http\Controllers\Agent;

use App\Domains\Infrastructure\Actions\RedeemEnrollmentToken;
use App\Http\Requests\Agent\EnrollRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EnrollmentController extends AgentController
{
    public function store(EnrollRequest $request, RedeemEnrollmentToken $redeemEnrollment): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $redeemEnrollment->execute(
                $validated['token'],
                $validated['csr'],
                [
                    'agent_version' => $validated['agent_version'],
                    'hostname' => $validated['hostname'],
                    'operating_system' => $validated['operating_system'] ?? null,
                    'architecture' => $validated['architecture'] ?? null,
                    'docker_version' => $validated['docker_version'] ?? null,
                    'machine_id' => $validated['machine_id'] ?? null,
                ],
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Enrollment failed.',
                'code' => 'enrollment_failed',
                'errors' => $exception->errors(),
            ], 422);
        }

        $certificate = $result['certificate'];

        return response()->json([
            'protocol_version' => (string) config('cloudforge.agent.protocol_version', '1.0'),
            'server' => [
                'id' => $result['server']->id,
                'name' => $result['server']->name,
                'hostname' => $result['server']->hostname,
            ],
            'certificate' => [
                'pem' => $certificate->certificate_pem,
                'serial' => $certificate->serial,
                'fingerprint_sha256' => $certificate->fingerprint_sha256,
                'expires_at' => $certificate->expires_at->toIso8601String(),
            ],
            'ca_certificate' => $result['ca_certificate'],
            'heartbeat_interval_seconds' => 30,
            'command_poll_interval_seconds' => 5,
        ], 201);
    }
}
