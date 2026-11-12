<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform identity
    |--------------------------------------------------------------------------
    |
    | Values surfaced to the dashboard and generated installation commands.
    |
    */

    'name' => env('APP_NAME', 'CloudForge'),

    'registration' => [
        'enabled' => (bool) env('CLOUDFORGE_ALLOW_REGISTRATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent protocol
    |--------------------------------------------------------------------------
    |
    | Phase 1 uses outbound HTTPS from the agent. Requests are signed with the
    | agent's certificate key and enrolment tokens are single-use. The optional
    | client certificate header lets a trusted TLS terminator bind the verified
    | TLS identity to the application request.
    |
    */

    'agent' => [
        'protocol_version' => '1.0',
        'enrollment_token_ttl_minutes' => (int) env('CLOUDFORGE_AGENT_ENROLLMENT_TTL_MINUTES', 15),
        'heartbeat_timeout_seconds' => (int) env('CLOUDFORGE_AGENT_HEARTBEAT_TIMEOUT_SECONDS', 90),
        'certificate_ttl_days' => (int) env('CLOUDFORGE_AGENT_CERTIFICATE_TTL_DAYS', 365),
        'ca_path' => env('CLOUDFORGE_AGENT_CA_PATH') ?: storage_path('app/private/agent-ca'),
        'ca_common_name' => env('CLOUDFORGE_AGENT_CA_COMMON_NAME', 'CloudForge Agent CA'),
        'command_ttl_seconds' => (int) env('CLOUDFORGE_AGENT_COMMAND_TTL_SECONDS', 300),
        'max_poll_wait_seconds' => (int) env('CLOUDFORGE_AGENT_MAX_POLL_WAIT_SECONDS', 25),
        'request_clock_skew_seconds' => (int) env('CLOUDFORGE_AGENT_CLOCK_SKEW_SECONDS', 300),
        'require_client_certificate_header' => (bool) env('CLOUDFORGE_REQUIRE_CLIENT_CERT_HEADER', false),
        'client_certificate_serial_header' => env('CLOUDFORGE_CLIENT_CERT_SERIAL_HEADER', 'X-Client-Cert-Serial'),
        'docker_socket' => env('CLOUDFORGE_DOCKER_SOCKET', '/var/run/docker.sock'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-hosted bootstrap
    |--------------------------------------------------------------------------
    |
    | When CloudForge is installed on its own VPS, the control plane inspects
    | the local host and provisions an unclaimed bootstrap node. The generated
    | enrollment token is written only to a 0600 shell environment file so the
    | installation wrapper can pass it to the agent without exposing it in
    | process arguments, browser JavaScript or application logs.
    |
    */

    'bootstrap' => [
        'control_plane_url' => env('CLOUDFORGE_CONTROL_PLANE_URL') ?: env('APP_URL', 'http://localhost'),
        'credential_path' => env('CLOUDFORGE_BOOTSTRAP_CREDENTIAL_PATH') ?: '/etc/cloudforge/bootstrap.env',
        'status_path' => env('CLOUDFORGE_BOOTSTRAP_STATUS_PATH') ?: '/etc/cloudforge/bootstrap.json',
        'token_ttl_minutes' => (int) env('CLOUDFORGE_BOOTSTRAP_TOKEN_TTL_MINUTES', 30),
        'server_name' => env('CLOUDFORGE_BOOTSTRAP_SERVER_NAME'),
        'machine_id' => env('CLOUDFORGE_MACHINE_ID'),
        'machine_id_path' => env('CLOUDFORGE_MACHINE_ID_PATH') ?: '/etc/machine-id',
        'dbus_machine_id_path' => env('CLOUDFORGE_DBUS_MACHINE_ID_PATH') ?: '/var/lib/dbus/machine-id',
        'os_release_path' => env('CLOUDFORGE_OS_RELEASE_PATH') ?: '/etc/os-release',
        'meminfo_path' => env('CLOUDFORGE_MEMINFO_PATH') ?: '/proc/meminfo',
        'cpuinfo_path' => env('CLOUDFORGE_CPUINFO_PATH') ?: '/proc/cpuinfo',
    ],

    'install' => [
        'script_url' => env('CLOUDFORGE_AGENT_INSTALL_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/install.sh'),
    ],

    'metrics' => [
        'retention_days' => (int) env('CLOUDFORGE_METRICS_RETENTION_DAYS', 14),
        'max_points_per_request' => 240,
    ],

];
