<?php
declare(strict_types=1);

namespace Velo\Router\Route;

class Route
{
    private array $middlewares;

    public function __construct(
        public readonly string $requestMethod,
        public readonly string $path,
        public readonly string $controller,
        public readonly string $action,
    )
    {
        $this->middlewares = [];
    }

    public function getMiddleware(int $index): ?array
    {
        return $this->middlewares[$index] ?? null;
    }

    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }

    public function addMiddleware(string $middlewareClass, mixed ...$arguments): self
    {
        $this->middlewares[] = [$middlewareClass, $arguments];
        return $this;
    }

    /**
     * @param array{0: string, 1?: mixed, ...} ...$middlewares
     */
    public function addMiddlewares(array ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->addMiddleware(...$middleware);
        }

        return $this;
    }
}