<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstallerScriptTest extends TestCase
{
    public function test_installer_scripts_parse_with_a_posix_shell(): void
    {
        $shell = $this->posixShell();

        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell is available to parse the installer scripts.');
        }

        foreach ([$this->installerPath(), $this->bootstrapScriptPath()] as $script) {
            $process = new Process([$shell, '-n', $this->shellPath($script)]);
            $process->setTimeout(30);
            $process->run();

            $this->assertSame(
                0,
                $process->getExitCode(),
                sprintf('%s failed to parse: %s', basename($script), $process->getErrorOutput()),
            );
        }
    }

    public function test_installer_enforces_the_bootstrap_credential_contract(): void
    {
        $shell = $this->posixShell();

        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell is available to run the installer harness.');
        }

        $process = new Process([
            $shell,
            $this->shellPath(base_path('tests/shell/installer_test.sh')),
            $this->shellPath($this->installerPath()),
        ]);
        $process->setTimeout(120);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('0 failures', $output);
        $this->assertStringNotContainsString('not ok', $output);
    }

    public function test_one_command_installer_parses_and_passes_contract_tests(): void
    {
        $bash = $this->bashPath();

        if ($bash === null) {
            $this->markTestSkipped('No Bash is available to run the one-command installer checks.');
        }

        $installer = base_path('../../install.sh');

        $parse = new Process([$bash, '-n', $this->shellPath($installer)]);
        $parse->setTimeout(30);
        $parse->run();

        $this->assertSame(
            0,
            $parse->getExitCode(),
            sprintf('install.sh failed to parse: %s', $parse->getErrorOutput()),
        );

        $harness = base_path('tests/shell/one_command_installer_test.sh');

        $process = new Process([
            $bash,
            $this->shellPath($harness),
            $this->shellPath($installer),
        ]);
        $process->setTimeout(120);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('0 failures', $output);
        $this->assertStringNotContainsString('not ok', $output);
    }

    private function installerPath(): string
    {
        return public_path('install.sh');
    }

    private function bootstrapScriptPath(): string
    {
        return base_path('../../infrastructure/scripts/bootstrap-node.sh');
    }

    private function posixShell(): ?string
    {
        foreach ($this->shellCandidates() as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function bashPath(): ?string
    {
        $configured = getenv('CLOUDFORGE_TEST_BASH');

        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['C:/Program Files/Git/bin/bash.exe', 'C:/Program Files/Git/usr/bin/bash.exe']
            : ['/bin/bash', '/usr/bin/bash'];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function shellCandidates(): array
    {
        $candidates = [];

        $configured = getenv('CLOUDFORGE_TEST_SHELL');

        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidates[] = 'C:/Program Files/Git/bin/sh.exe';
            $candidates[] = 'C:/Program Files/Git/usr/bin/sh.exe';
        } else {
            $candidates[] = '/bin/sh';
        }

        return $candidates;
    }

    private function shellPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^([A-Za-z]):\//', $normalized, $matches) === 1) {
            return '/'.strtolower($matches[1]).substr($normalized, 2);
        }

        return $normalized;
    }
}
