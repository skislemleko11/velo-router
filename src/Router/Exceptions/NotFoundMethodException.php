<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use Velo\Router\Exceptions\Interfaces\HttpExceptionInterface;

class NotFoundMethodException extends RouterException implements NotFoundExceptionInterface, HttpExceptionInterface
{
    protected $message = "The requested method was not found.";

    public function getStatusCode(): int
    {
        return 404;
    }

    public function shouldLogException(): bool
    {
        return true;
    }
}