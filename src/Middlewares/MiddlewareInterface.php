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
     * Handles the given HttpRequset.
     *
     * Classes implementing MiddlewareInterface can add optional parameters to method's declaration
     * for more complicated logic.
     *
     * @param callable $next Should take HttpRequest and additional params if needed.
     */
    public function handle(HttpRequest $request, callable $next): HttpResponse;
}