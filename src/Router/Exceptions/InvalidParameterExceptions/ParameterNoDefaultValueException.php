<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

use Exception;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\Interfaces\InvalidParameterExceptionInterface;

final class ParameterNoDefaultValueException extends Exception implements InvalidParameterExceptionInterface
{
    protected $message = 'Parameter has no default value!';
}