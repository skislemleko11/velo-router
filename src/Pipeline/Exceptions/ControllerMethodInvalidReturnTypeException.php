<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline\Exceptions;

use Exception;
use Velo\Http\Responses\Response;
use Velo\Router\Pipeline\Exceptions\Interfaces\PipelineExceptionInterface;

class ControllerMethodInvalidReturnTypeException extends Exception implements PipelineExceptionInterface
{
    protected $message = 'Invalid return type of controller function! It must be ' . Response::class;
}