<?php
declare(strict_types=1);

namespace Velo\Router\Route;

use Velo\Router\Middlewares\AddMiddlewaresTrait;
use Velo\Router\Middlewares\MiddlewareInterface;

class Route
{
    use AddMiddlewaresTrait;

    public function __construct(
        public readonly string $requestMethod,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
    )
    {
    }

    public function getMiddleware(int $index): MiddlewareInterface|string|array|callable|null
    {
        return $this->middlewares[$index] ?? null;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }
}