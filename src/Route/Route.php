<?php
declare(strict_types=1);

namespace Velo\Router\Route;

use Velo\Router\Middlewares\AddMiddlewaresTrait;
use Velo\Router\Middlewares\MiddlewareInterface;

/**
 * Represnts Route, it's registered in Router Class.
 */
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

    /**
     * Gets the middleware at the given index. Returns null if it's not set.
     *
     * @return MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable|null
     */
    public function getMiddleware(int $index): MiddlewareInterface|string|array|callable|null
    {
        return $this->middlewares[$index] ?? null;
    }

    /**
     * Getter method for middlewares array.
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Returns the count (length) of middlewares array.
     */
    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }
}