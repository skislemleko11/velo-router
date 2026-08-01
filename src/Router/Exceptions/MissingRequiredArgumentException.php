<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

class MissingRequiredArgumentException extends RouterException
{
    protected $message = "Missing required argument in an object's method!";
}