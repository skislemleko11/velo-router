<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

class ParameterNoDefaultValueException extends InvalidParameterException
{
    protected $message = 'Parameter has no default value!';
}