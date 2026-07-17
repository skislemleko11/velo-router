<?php

namespace Velo\Router\Exceptions;

use Exception;
use Psr\Container\NotFoundExceptionInterface;
use Velo\Router\Exceptions\Interfaces\HttpExceptionInterface;

class NotFoundMethodException extends Exception implements NotFoundExceptionInterface, HttpExceptionInterface
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