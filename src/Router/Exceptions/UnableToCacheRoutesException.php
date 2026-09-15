<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Exception;

final class UnableToCacheRoutesException extends Exception implements RouterExceptionInterface
{
    protected $message = 'Unable to cache routes to the given file path!';
}