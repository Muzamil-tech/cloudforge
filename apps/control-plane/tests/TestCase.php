<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    protected string $agentCaPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agentCaPath = storage_path('framework/testing/agent-ca-'.Str::uuid());
        config(['cloudforge.agent.ca_path' => $this->agentCaPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->agentCaPath);

        parent::tearDown();
    }
}
