<?php
declare(strict_types=1);

namespace Velo\Router\Exceptions;

use Exception;

class MustImplementMiddlewareInterfaceException extends Exception
{
    protected $message = 'Middleware classes must implement the MiddlewareInterface!';
}