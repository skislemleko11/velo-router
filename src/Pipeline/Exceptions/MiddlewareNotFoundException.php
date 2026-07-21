<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

class MiddlewareNotFoundException extends PipelineException
{
    protected $message = 'Middleware not found!';
}