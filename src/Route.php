<?php
declare(strict_types=1);

namespace Velo\Router;

use Velo\Http\Interfaces\MiddlewareInterface;

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

    public function getMiddleware(int $index): ?string
    {
        return $this->middlewares[$index] ?? null;
    }

    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }

    public function middlewares(string $middlewareClass): self
    {
        $this->middlewares[] = $middlewareClass;
        return $this;
    }
}