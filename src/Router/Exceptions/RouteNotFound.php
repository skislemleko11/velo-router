<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Velo\Exceptions\NotFoundException;
use Velo\Router\Router\Exceptions\Interfaces\RouterExceptionInterface;

/**
 * This Exception should be thrown to trigger a 404 Page Not Found error.
 */
class RouteNotFound extends NotFoundException implements RouterExceptionInterface
{
    protected $message = 'Route not found!';
}