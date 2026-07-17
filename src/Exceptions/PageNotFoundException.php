<?php

namespace Velo\Router\Exceptions;

use Exception;
use Velo\Router\Exceptions\Interfaces\HttpExceptionInterface;

class PageNotFoundException extends Exception implements HttpExceptionInterface
{
    protected $message = 'Page not found!';

    public function getStatusCode(): int
    {
        return 404;
    }

    public function shouldLogException(): bool
    {
        return false;
    }
}