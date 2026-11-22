<?php

namespace App\DataSource\Exception;

final class DataSourceUnavailableException extends \RuntimeException
{
    public static function notConfigured(string $source, string $variables): self
    {
        return new self(\sprintf('%s is not configured: set %s, or enable the demo mode with APP_DEMO=1.', $source, $variables));
    }

    public static function failed(string $source, \Throwable $previous): self
    {
        return new self(\sprintf('%s did not answer correctly.', $source), previous: $previous);
    }
}
