<?php

namespace Velo\Router\Exceptions;

use Exception;
use Psr\Container\NotFoundExceptionInterface;
use Velo\Router\Exceptions\Interfaces\HttpExceptionInterface;

class NotFoundControllerException extends Exception implements NotFoundExceptionInterface, HttpExceptionInterface
{
    protected $message = "The requested controller was not found.";

    public function getStatusCode(): int
    {
        return 404;
    }

    public function shouldLogException(): bool
    {
        return false;
    }
}