<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

use Exception;

final class MustImplementMiddlewareInterfaceException extends Exception implements PipelineExceptionInterface
{
    protected $message = 'Middleware classes must implement the MiddlewareInterface!';
}