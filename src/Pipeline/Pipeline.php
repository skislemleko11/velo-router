<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Http\Interfaces\MiddlewareInterface;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MiddlewareNotFoundException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Route\Route;

readonly class Pipeline
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * Main, universal method running the given chain of middlewares.
     * Used for the global pipeline.
     * @param HttpRequest $request
     * @param array<MiddlewareInterface|array{0: string, 1?: mixed, ...}|string> $middlewares
     * @param callable $destination
     * @return HttpResponse
     * @throws ContainerExceptionInterface
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundExceptionInterface
     * @throws MiddlewareNotFoundException
     */
    public function executeMiddlewaresChain(HttpRequest $request, array $middlewares, callable $destination): HttpResponse
    {
        $index = 0;

        /**
         * @throws NotFoundExceptionInterface
         * @throws ContainerExceptionInterface
         * @throws MustImplementMiddlewareInterfaceException
         * @throws MiddlewareNotFoundException
         */
        $next = function (HttpRequest $request) use (&$index, &$middlewares, $destination, &$next) {
            if ($index >= count($middlewares)) {
                return $destination($request);
            }

            $middleware = $middlewares[$index];
            $index++;

            $arguments = [];

            if ($middleware instanceof MiddlewareInterface) {
                $middlewareInstance = $middleware;
            } elseif (is_array($middleware)) {
                $middlewareClass = $middleware[0];
                $arguments = $middleware[1] ?? [];
                $middlewareInstance = $this->container->get($middlewareClass);
            } elseif (is_string($middleware)) {
                $middlewareInstance = $this->container->get($middleware);
            } else {
                throw new MustImplementMiddlewareInterfaceException(
                    "Middleware must implement " . MiddlewareInterface::class
                );
            }

            if (!$middlewareInstance instanceof MiddlewareInterface) {
                throw new MustImplementMiddlewareInterfaceException(
                    'Class ' . $middlewareInstance::class . ' must implement ' . MiddlewareInterface::class . "!"
                );
            }

            return $middlewareInstance->handle($request, $next, ...$arguments);
        };

        return $next($request);
    }

    /**
     * Method dedicated for Routes' middlewares
     * It uses the main method
     * @param Route $route
     * @param HttpRequest $request
     * @param array $castedArgs
     * @return HttpResponse
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MiddlewareNotFoundException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundExceptionInterface
     */
    public function executeRoutesMiddlewaresChain(Route $route, HttpRequest $request, array $castedArgs): HttpResponse
    {
        $middlewares = $route->getMiddlewares();
        $destination = fn(HttpRequest $req) => $this->coreAction($route, $req, $castedArgs);

        return $this->executeMiddlewaresChain($request, $middlewares, $destination);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     */
    private function coreAction(Route $route, HttpRequest $request, array $castedArgs): HttpResponse
    {
        $controllerInstance = $this->container->get($route->controller);
        $result = $controllerInstance->{$route->action}($request, ...$castedArgs);

        if (!$result instanceof HttpResponse) {
            throw new ControllerMethodInvalidReturnTypeException(
                'Invalid return type of controller ' . $controllerInstance::class .
                ' function! It must be ' . HttpResponse::class
            );
        }

        return $result;
    }
}