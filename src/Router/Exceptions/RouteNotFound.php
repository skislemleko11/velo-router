<?php
declare(strict_types=1);

namespace Velo\Router\Router\Exceptions;

use Velo\Exceptions\NotFoundException;

/**
 * This Exception should be thrown to trigger a 404 Page Not Found error.
 */
final class RouteNotFound extends NotFoundException implements RouterExceptionInterface
{
    protected $message = 'Route not found!';
}