<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Velo\Exceptions\NotFoundException;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

class NotFoundControllerMethodException extends NotFoundException implements RouterExceptionInterface
{
    protected $message = 'The requested controller method was not found!';

    public function shouldLogException(): bool
    {
        return true;
    }
}