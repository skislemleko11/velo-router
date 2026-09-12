<?php
declare(strict_types=1);

namespace Velo\Router\Router\Interfaces;

use Velo\Http\Request;
use Velo\Http\RequestMethod;
use Velo\Router\Route;

interface CorsRouterExtensionInterface
{
    public function isPreflight(Request $request): bool;

    public function getRequestedMethodFromPreflight(Request $request): RequestMethod;

    public function hasCorsMiddleware(Route $route): bool;
}