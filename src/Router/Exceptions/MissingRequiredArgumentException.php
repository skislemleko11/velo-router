<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Exception;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

class MissingRequiredArgumentException extends Exception implements RouterExceptionInterface
{
    protected $message = "Missing required argument in an object's method!";
}