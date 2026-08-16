<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Exception;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

class UnableToLoadRoutesException extends Exception implements RouterExceptionInterface
{
    protected $message = 'Router was unable to load routes either from cache file or registry file.';
}