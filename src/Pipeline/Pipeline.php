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

    public function executeMiddlewareChain(Route $route, HttpRequest $request, array $castedArgs): HttpResponse
    {
        $index = 0;

        /**
         * @throws MustImplementMiddlewareInterfaceException
         * @throws ContainerExceptionInterface
         * @throws NotFoundExceptionInterface
         * @throws ControllerMethodInvalidReturnTypeException
         * @throws MiddlewareNotFoundException
         */
        $next = function (HttpRequest $request) use ($route, &$index, &$castedArgs, &$next) {
            if ($index >= $route->getMiddlewaresCount())
                return $this->coreAction($route, $request, $castedArgs);

            $middleware = $route->getMiddleware($index);
            $index++;

            if ($middleware) {
                $middlewareClass = $middleware[0];
                $arguments = $middleware[1];

                $middlewareInstance = $this->container->get($middlewareClass);

                if (!$middlewareInstance instanceof MiddlewareInterface)
                    throw new MustImplementMiddlewareInterfaceException(
                        "Class $middlewareClass must implement MiddlewareInterface!");

                return $middlewareInstance->handle($request, $next, ...$arguments);
            }

            throw new MiddlewareNotFoundException();
        };

        return $next($request);
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

        if (!$result instanceof HttpResponse)
            throw new ControllerMethodInvalidReturnTypeException(
                'Invalid return type of controller ' . $controllerInstance::class .
                ' function! It must be ' . HttpResponse::class
            );

        return $result;
    }
}