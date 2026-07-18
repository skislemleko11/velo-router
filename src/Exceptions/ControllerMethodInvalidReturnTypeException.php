<?php
declare(strict_types=1);

namespace Velo\Router\Exceptions;

use Exception;
use Velo\Http\HttpResponse;

class ControllerMethodInvalidReturnTypeException extends Exception
{
    protected $message = 'Invalid return type of controller function! It must be ' . HttpResponse::class;
}