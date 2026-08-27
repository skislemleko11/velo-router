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
     * Middleware class name/ID binded in the DI Container or an array of class name/ID binded in the DI Container
     * and array of args which will be passed to the 'handle' middleware's method,
     * or callable - facory function which must return an instance of MiddlewareInterface.
     * Passing an already instanciated object is also possible, but not recommended.
     * Passing a factory callable is possible as well, but it's not recommended either,
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
     * Middleware class name/ID binded in the DI Container or an array of class name/ID binded in the DI Container
     * and array of args which will be passed to the 'handle' middleware's method,
     * or callable - facory function which must return an instance of MiddlewareInterface.
     * Passing an already instanciated object is also possible, but not recommended.
     * Passing a factory callable is possible as well, but it's not recommended either,
     * because it will cause errors with caching Routes in Router class.
     * Use already instanciated objects and callables only for tests and development usage.
     */
    public function addMiddlewares(string|array|MiddlewareInterface|callable ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->addMiddleware($middleware);
        }

        return $this;
    }
}