<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('environment_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('status')->default('provisioning');
            $table->string('image');
            $table->string('container_name')->unique();
            $table->string('volume_name')->unique();
            $table->string('container_id')->nullable();
            $table->string('host')->default('127.0.0.1');
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('database_name')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamp('credentials_revealed_at')->nullable();
            $table->uuid('last_command_id')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'environment_id']);
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
