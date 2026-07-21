<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

class MustImplementMiddlewareInterfaceException extends PipelineException
{
    protected $message = 'Middleware classes must implement the MiddlewareInterface!';
}