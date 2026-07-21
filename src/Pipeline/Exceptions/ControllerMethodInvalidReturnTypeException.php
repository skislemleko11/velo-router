<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

use Velo\Http\HttpResponse;

class ControllerMethodInvalidReturnTypeException extends PipelineException
{
    protected $message = 'Invalid return type of controller function! It must be ' . HttpResponse::class;
}