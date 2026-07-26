<?php
declare(strict_types=1);

namespace Velo\Router\Middlewares;

use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;

/**
 * Enforces implementation of hadnle method for middlewares. All middlewares should implement it!
 */
interface MiddlewareInterface
{
    /**
     * @param callable $next should take HttpRequest
     */
    public function handle(HttpRequest $request, callable $next): HttpResponse;
}