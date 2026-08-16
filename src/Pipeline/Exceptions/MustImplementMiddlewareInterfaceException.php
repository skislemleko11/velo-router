<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

use Exception;
use Velo\Router\Pipeline\Exceptions\Interfaces\PipelineExceptionInterface;

class MustImplementMiddlewareInterfaceException extends Exception implements PipelineExceptionInterface
{
    protected $message = 'Middleware classes must implement the MiddlewareInterface!';
}