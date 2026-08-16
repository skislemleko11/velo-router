<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

use Exception;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\Interfaces\InvalidParameterExceptionInterface;

class ParameterIntersectionTypeException extends Exception implements InvalidParameterExceptionInterface
{
    protected $message = 'Parameter cannot be of an intersection type!';
}