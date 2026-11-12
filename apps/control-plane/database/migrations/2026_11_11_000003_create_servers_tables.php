<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('machine_id')->nullable()->unique();
            $table->boolean('is_bootstrap')->default(false);
            $table->timestamp('claimed_at')->nullable();
            $table->string('name');
            $table->string('hostname');
            $table->string('public_ip', 45)->nullable();
            $table->string('private_ip', 45)->nullable();
            $table->string('region')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('architecture')->nullable();
            $table->unsignedInteger('cpu_cores')->nullable();
            $table->unsignedBigInteger('total_memory_bytes')->nullable();
            $table->unsignedBigInteger('total_disk_bytes')->nullable();
            $table->string('docker_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('status')->default('installation_pending')->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('last_inventory_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('server_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->timestamp('created_at')->nullable();

            $table->unique(['server_id', 'role']);
        });

        Schema::create('server_capabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->string('capability');
            $table->string('version')->nullable();
            $table->json('metadata')->nullable();

            $table->unique(['server_id', 'capability']);
        });

        Schema::create('server_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose')->default('installation');

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['server_id', 'expires_at'], 'expires_at_index');
            $table->index(['server_id', 'consumed_at'], 'consumed_at_index');
        });

        Schema::create('server_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->string('serial')->unique();
            $table->string('fingerprint_sha256', 64)->unique();
            $table->text('certificate_pem');
            $table->timestamp('issued_at')->default(now());
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'issued_at'], 'certificate_issued_at_index');
            $table->index(['server_id', 'expires_at'], 'certificate_expires_at_index');
            $table->index(['server_id', 'revoked_at'], 'certificate_revoked_at_index');
        });

        Schema::create('server_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->decimal('cpu_usage', 5, 2)->nullable();
            $table->decimal('memory_usage', 5, 2)->nullable();
            $table->decimal('disk_usage', 5, 2)->nullable();
            $table->unsignedBigInteger('memory_used_bytes')->nullable();
            $table->unsignedBigInteger('disk_used_bytes')->nullable();
            $table->unsignedBigInteger('network_in_bytes')->nullable();
            $table->unsignedBigInteger('network_out_bytes')->nullable();
            $table->json('metadata')->nullable();

            $table->index(['server_id', 'recorded_at'], 'recorded_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_metrics');
        Schema::dropIfExists('server_certificates');
        Schema::dropIfExists('server_enrollments');
        Schema::dropIfExists('server_capabilities');
        Schema::dropIfExists('server_roles');
        Schema::dropIfExists('servers');
    }
};
