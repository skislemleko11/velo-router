<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

class ParameterIntersectionTypeException extends InvalidParameterException
{
    protected $message = 'Parameter cannot be of an intersection type!';
}