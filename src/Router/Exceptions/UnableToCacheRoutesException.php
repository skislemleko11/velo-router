<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

class UnableToCacheRoutesException extends RouterException
{
    protected $message = 'Unable to cache routes to the given file path!';
}