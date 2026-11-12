<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('environment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('image');
            $table->string('container_name')->unique();
            $table->string('container_id')->nullable();
            $table->unsignedSmallInteger('container_port')->default(3000);
            $table->string('host')->default('127.0.0.1');
            $table->unsignedSmallInteger('host_port')->nullable();
            $table->string('health_path')->nullable();
            $table->string('status')->default('deploying');
            $table->uuid('last_command_id')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'environment_id', 'slug']);
            $table->index(['organization_id', 'status']);
            $table->index(['server_id', 'status']);
            $table->index(['project_id', 'environment_id']);
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('environment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('application_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('server_id')->constrained()->restrictOnDelete();
            $table->string('image');
            $table->string('container_name')->unique();
            $table->string('container_id')->nullable();
            $table->unsignedSmallInteger('host_port')->nullable();
            $table->string('status')->default('deploying');
            $table->uuid('command_id')->nullable();
            $table->text('logs')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'created_at']);
            $table->index(['organization_id', 'status']);
            $table->index('command_id');
        });

        Schema::create('deployment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('deployment_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_events');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('applications');
    }
};
