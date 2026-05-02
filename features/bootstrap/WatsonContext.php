<?php

declare(strict_types=1);

namespace Watson\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Symfony\Component\Process\Process;

/**
 * Behat step definitions. Each scenario boots the relevant fixture app's
 * console and shells the watson command exposed via auto-discovery /
 * bundle registration. We assert against the JSON envelope on stdout —
 * that's the same JSON LLM consumers will read.
 */
final class WatsonContext implements Context
{
    private string $fixturePath = '';
    private string $stdout = '';
    /** @var array<string,string> relative path => original contents */
    private array $touchedFiles = [];
    private string $baseSha = '';

    #[Given('the Laravel fixture')]
    public function laravelFixture(): void
    {
        $this->fixturePath = __DIR__ . '/../../fixtures/laravel-app';
    }

    #[Given('the Symfony fixture')]
    public function symfonyFixture(): void
    {
        $this->fixturePath = __DIR__ . '/../../fixtures/symfony-app';
    }

    #[Given('the easy-plu Laravel app at WATSON_EASY_PLU_ROOT')]
    public function easyPluApp(): void
    {
        $root = getenv('WATSON_EASY_PLU_ROOT');
        if (!is_string($root) || $root === '' || !is_dir($root)) {
            throw new \RuntimeException('WATSON_EASY_PLU_ROOT not set or path missing');
        }
        $this->fixturePath = $root;
    }

    #[When('/^I run "([^"]+)" via artisan$/')]
    public function runArtisan(string $command): void
    {
        // -d display_errors=stderr keeps PHP's own deprecation notices out
        // of stdout so the watson JSON is the only thing on the channel.
        $argv = ['php', '-d', 'display_errors=stderr', 'artisan', ...self::splitCommand($command), '--format=json'];
        $process = new Process($argv, $this->fixturePath);
        $this->run($process);
    }

    #[When('/^I run "([^"]+)" via bin\/console$/')]
    public function runBinConsole(string $command): void
    {
        $argv = ['php', '-d', 'display_errors=stderr', 'bin/console', ...self::splitCommand($command), '--format=json'];
        $process = new Process($argv, $this->fixturePath);
        $this->run($process);
    }

    /** @return list<string> */
    private static function splitCommand(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));

        return $parts === false ? [$command] : array_values(array_filter($parts, static fn ($p) => $p !== ''));
    }

    private function run(Process $process): void
    {
        $process->run();
        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "watson command failed (exit %d)\n--- stdout ---\n%s\n--- stderr ---\n%s",
                (int) $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }
        $this->stdout = $process->getOutput();
    }

    #[Then('the JSON output contains at least :n entry points')]
    public function jsonContainsAtLeastNEntryPoints(int $n): void
    {
        $envelope = $this->parseEnvelope();
        $count = count($envelope['analyses'][0]['result']['entry_points'] ?? []);
        if ($count < $n) {
            throw new \RuntimeException(sprintf('expected >= %d entry points, got %d', $n, $count));
        }
    }

    #[Then('every entry point should be tagged source = :expected')]
    public function everyEntryPointSourceIs(string $expected): void
    {
        $envelope = $this->parseEnvelope();
        $entryPoints = $envelope['analyses'][0]['result']['entry_points'] ?? [];

        foreach ($entryPoints as $ep) {
            if (($ep['source'] ?? null) !== $expected) {
                throw new \RuntimeException(sprintf(
                    'expected source=%s on every entry point; saw "%s" on %s',
                    $expected,
                    $ep['source'] ?? '(missing)',
                    $ep['handler_fqn'] ?? '(unknown)',
                ));
            }
        }
    }

    #[Then('the JSON output contains entry points of kind :kind')]
    public function jsonContainsKind(string $kind): void
    {
        $envelope = $this->parseEnvelope();
        $eps = $envelope['analyses'][0]['result']['entry_points'] ?? [];
        foreach ($eps as $ep) {
            if (($ep['kind'] ?? null) === $kind) {
                return;
            }
        }
        throw new \RuntimeException(sprintf('no entry points of kind=%s in output', $kind));
    }

    #[Then('every :kind entry point should be tagged source = :expected')]
    public function everyKindEntryPointSourceIs(string $kind, string $expected): void
    {
        $envelope = $this->parseEnvelope();
        $entryPoints = $envelope['analyses'][0]['result']['entry_points'] ?? [];

        $matched = 0;
        foreach ($entryPoints as $ep) {
            if (($ep['kind'] ?? null) !== $kind) {
                continue;
            }
            $matched++;
            if (($ep['source'] ?? null) !== $expected) {
                throw new \RuntimeException(sprintf(
                    'kind=%s expected source=%s; saw "%s" on %s',
                    $kind,
                    $expected,
                    $ep['source'] ?? '(missing)',
                    $ep['handler_fqn'] ?? '(unknown)',
                ));
            }
        }
        if ($matched === 0) {
            throw new \RuntimeException(sprintf('no entry points of kind=%s present', $kind));
        }
    }

    #[Then('the framework should be reported as :expected')]
    public function frameworkShouldBe(string $expected): void
    {
        $envelope = $this->parseEnvelope();
        if (($envelope['framework'] ?? null) !== $expected) {
            throw new \RuntimeException(sprintf(
                'expected framework=%s, got "%s"',
                $expected,
                $envelope['framework'] ?? '(missing)',
            ));
        }
    }

    #[Given('the working tree starts clean from :relative')]
    public function workingTreeStartsCleanFrom(string $relative): void
    {
        $abs = $this->fixturePath . '/' . $relative;
        if (!is_file($abs)) {
            throw new \RuntimeException("fixture file missing: {$abs}");
        }
        $this->touchedFiles[$relative] = (string) file_get_contents($abs);

        // Snapshot the current commit so blastradius has a base to diff
        // against. The fixture lives inside the watson repo, so HEAD is fine.
        $proc = new Process(['git', 'rev-parse', 'HEAD'], $this->fixturePath);
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new \RuntimeException('git rev-parse HEAD failed: ' . $proc->getErrorOutput());
        }
        $this->baseSha = trim($proc->getOutput());
    }

    #[Given('I edit :relative')]
    public function iEdit(string $relative): void
    {
        $abs = $this->fixturePath . '/' . $relative;
        if (!isset($this->touchedFiles[$relative])) {
            // Snapshot anyway so AfterScenario can restore.
            $this->touchedFiles[$relative] = (string) file_get_contents($abs);
        }
        $original = $this->touchedFiles[$relative];
        // Append a no-op trailing comment that still creates a real diff hunk
        // touching the file so changedFiles() picks it up.
        $modified = rtrim($original) . "\n// watson-test-edit " . uniqid('', true) . "\n";
        if (file_put_contents($abs, $modified) === false) {
            throw new \RuntimeException("failed to write fixture: {$abs}");
        }
    }

    #[When('/^I run "([^"]+)" against the working-tree diff via artisan$/')]
    public function runArtisanBlastradius(string $command): void
    {
        // --scope=routes keeps the assertion meaningful when the watson
        // repo and fixture share the same git tree: a wider scope would
        // pull in the package files themselves into the affected set.
        $process = new Process(
            ['php', '-d', 'display_errors=stderr', 'artisan', $command, $this->baseSha, '--format=json', '--scope=routes'],
            $this->fixturePath,
        );
        $this->run($process);
    }

    #[When('/^I run "([^"]+)" against the working-tree diff via bin\/console$/')]
    public function runBinConsoleBlastradius(string $command): void
    {
        $process = new Process(
            ['php', '-d', 'display_errors=stderr', 'bin/console', $command, $this->baseSha, '--format=json', '--scope=routes'],
            $this->fixturePath,
        );
        $this->run($process);
    }

    #[Then('the JSON output reports at least :n affected entry points')]
    public function jsonReportsAtLeastNAffectedEntryPoints(int $n): void
    {
        $envelope = $this->parseEnvelope();
        $count = count($envelope['analyses'][0]['result']['affected_entry_points'] ?? []);
        if ($count < $n) {
            throw new \RuntimeException(sprintf(
                'expected >= %d affected entry points, got %d',
                $n,
                $count,
            ));
        }
    }

    #[Then('the affected entry points are all of kind :expected')]
    public function affectedEntryPointsAllOfKind(string $expected): void
    {
        $envelope = $this->parseEnvelope();
        $affected = $envelope['analyses'][0]['result']['affected_entry_points'] ?? [];
        if ($affected === []) {
            throw new \RuntimeException('no affected entry points reported');
        }
        foreach ($affected as $ep) {
            if (($ep['kind'] ?? null) !== $expected) {
                throw new \RuntimeException(sprintf(
                    'expected kind=%s on every affected entry point; saw "%s"',
                    $expected,
                    $ep['kind'] ?? '(missing)',
                ));
            }
        }
    }

    #[AfterScenario]
    public function restoreTouchedFiles(): void
    {
        foreach ($this->touchedFiles as $relative => $original) {
            $abs = $this->fixturePath . '/' . $relative;
            @file_put_contents($abs, $original);
        }
        $this->touchedFiles = [];
    }

    /** @return array<string,mixed> */
    private function parseEnvelope(): array
    {
        $decoded = json_decode($this->stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('watson stdout was not valid JSON: ' . substr($this->stdout, 0, 200));
        }

        return $decoded;
    }
}
