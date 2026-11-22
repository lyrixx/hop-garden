<?php

namespace App\Tests\Utils\Env;

use PHPUnit\Framework\Attributes\After;

/**
 * Overrides env vars for one test, such as APP_DEMO=0 to leave the demo mode.
 */
trait EnvVarsTrait
{
    /** @var array<string, array{server: mixed, env: mixed}> the values to restore */
    private array $overriddenEnvVars = [];

    #[After]
    protected function restoreEnvVars(): void
    {
        foreach ($this->overriddenEnvVars as $name => $previous) {
            if (null === $previous['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $previous['server'];
            }

            if (null === $previous['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $previous['env'];
            }
        }

        $this->overriddenEnvVars = [];
    }

    /**
     * The container reads an env var when it builds a service: call it before booting the kernel.
     */
    private function setEnvVar(string $name, string $value): void
    {
        $this->overriddenEnvVars[$name] ??= ['server' => $_SERVER[$name] ?? null, 'env' => $_ENV[$name] ?? null];
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
}
