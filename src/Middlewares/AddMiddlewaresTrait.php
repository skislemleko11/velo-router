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
     * @param string|array|MiddlewareInterface{0: string, 1?: list<mixed>}|callable $middleware
     * Middleware class name or an array of binded ID and array of args or callable.
     * Passing an already instanciated object is also possible, but not recommended.
     * Passing a callable is possible as well, but it's not recommended either,
     * because it will cause errors with caching Routes in Router class.
     * Use already instanciated objects and callables only for tests and development usage.
     */
    public function addMiddleware(string|array|MiddlewareInterface|callable $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * @param string|array|MiddlewareInterface{0: string, 1?: list<mixed>}|callable ...$middlewares
     * Some: Middleware class name or an array of binded ID and array of args or callable.
     * Passing an already instanciated object is also possible, but not recommended.
     * Passing a callable is possible as well, but it's not recommended either,
     * because it will cause errors with caching Routes in Router class.
     * Use already instanciated objects and callables only for tests and development usage.
     * Pass every middleware after a comma.
     */
    public function addMiddlewares(string|array|MiddlewareInterface|callable ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->addMiddleware($middleware);
        }

        return $this;
    }
}