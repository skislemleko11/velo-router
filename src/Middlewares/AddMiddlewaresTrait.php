<?php
declare(strict_types=1);

namespace Velo\Router\Middlewares;

/**
 * Contains storing and adding middlewares.
 */
trait AddMiddlewaresTrait
{
    /**
     * @var list<MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable>
     */
    private array $middlewares = [];

    /**
     * @param MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable $middleware
     * Middleware Instance or string ID binded in Container or an array of binded ID and array of args or callable.
     */
    public function addMiddleware(MiddlewareInterface|string|array|callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * @param MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable ...$middlewares
     * Some: Middleware Instances or string IDs binded in Container or an array of binded ID and array of args or callable.
     * Pass every middleware after a comma.
     */
    public function addMiddlewares(MiddlewareInterface|string|array|callable ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->addMiddleware($middleware);
        }

        return $this;
    }
}