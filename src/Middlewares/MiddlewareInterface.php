<?php
declare(strict_types=1);

namespace Velo\Router\Middlewares;

use Velo\Http\Request;
use Velo\Http\Responses\Response;

/**
 * Enforces implementation of hadnle method for middlewares. All middlewares should implement it!
 */
interface MiddlewareInterface
{
    /**
     * Handles the given Request.
     *
     * Classes implementing MiddlewareInterface can add optional parameters to method's declaration
     * for more complicated logic.
     *
     * @param callable $next Should take Request.
     */
    public function handle(Request $request, callable $next): Response;
}