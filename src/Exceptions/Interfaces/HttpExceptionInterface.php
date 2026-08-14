<?php
declare(strict_types=1);

namespace Velo\Router\Exceptions\Interfaces;

/**
 * Enforces implementation of getStatusCode and shouldLogException for better handling of Exceptions
 * which should cause some HTTP effect and optionally logging.
 */
interface HttpExceptionInterface
{
    public function getStatusCode(): int;

    public function shouldLogException(): bool;
}