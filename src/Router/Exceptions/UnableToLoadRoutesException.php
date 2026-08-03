<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

class UnableToLoadRoutesException extends RouterException
{
    protected $message = 'Router was unable to load routes either from cache file or registry file.';
}