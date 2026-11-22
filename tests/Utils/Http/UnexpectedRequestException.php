<?php

namespace App\Tests\Utils\Http;

/**
 * Not an HTTP client exception on purpose: the API clients catch those and fall back silently.
 */
final class UnexpectedRequestException extends \LogicException
{
}
