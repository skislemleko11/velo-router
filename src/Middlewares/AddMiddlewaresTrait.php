<?php
declare(strict_types=1);

namespace Velo\Router\Middlewares;

/**
 * Contains storing and adding middlewares.
 */
trait AddMiddlewaresTrait
{
    /**
     * @var list<MiddlewareInterface|class-string|array{0: class-string, 1?: list<mixed>}|callable>
     */
    private array $middlewares = [];

    /**
     * @param MiddlewareInterface|class-string|array|callable $middleware
     */
    public function addMiddleware(MiddlewareInterface|string|array|callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * @param MiddlewareInterface|class-string|array|callable ...$middlewares
     */
    public function addMiddlewares(MiddlewareInterface|string|array|callable ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->addMiddleware($middleware);
        }

        return $this;
    }
}