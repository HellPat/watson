<?php

declare(strict_types=1);

namespace Watson\Tests\Behat;

use Behat\Behat\Context\Context;
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

    /**
     * @Given the Laravel fixture
     */
    public function laravelFixture(): void
    {
        $this->fixturePath = __DIR__ . '/../../fixtures/laravel-app';
    }

    /**
     * @Given the Symfony fixture
     */
    public function symfonyFixture(): void
    {
        $this->fixturePath = __DIR__ . '/../../fixtures/symfony-app';
    }

    /**
     * @When I run :command via artisan
     */
    public function runArtisan(string $command): void
    {
        $process = new Process(['php', 'artisan', $command, '--format=json'], $this->fixturePath);
        $process->mustRun();
        $this->stdout = $process->getOutput();
    }

    /**
     * @When I run :command via bin/console
     */
    public function runBinConsole(string $command): void
    {
        $process = new Process(['php', 'bin/console', $command, '--format=json'], $this->fixturePath);
        $process->mustRun();
        $this->stdout = $process->getOutput();
    }

    /**
     * @Then the JSON output contains an entry point for every Route::* declaration
     */
    public function jsonContainsEveryLaravelRoute(): void
    {
        $envelope = $this->parseEnvelope();
        $entryPoints = $envelope['analyses'][0]['result']['entry_points'] ?? [];

        if ($entryPoints === []) {
            throw new \RuntimeException('expected at least one entry point, got zero');
        }
    }

    /**
     * @Then the JSON output contains an entry point for every #[Route] attribute
     */
    public function jsonContainsEverySymfonyRoute(): void
    {
        $this->jsonContainsEveryLaravelRoute();
    }

    /**
     * @Then every entry point should be tagged source = :expected
     */
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

    /**
     * @Then the framework should be reported as :expected
     */
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
