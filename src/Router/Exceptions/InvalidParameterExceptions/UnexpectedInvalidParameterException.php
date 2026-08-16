<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions\InvalidParameterExceptions;

use Exception;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\Interfaces\InvalidParameterExceptionInterface;

class UnexpectedInvalidParameterException extends Exception implements InvalidParameterExceptionInterface
{

}