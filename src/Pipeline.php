<?php
declare(strict_types=1);

namespace Velo\Router;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Http\Interfaces\MiddlewareInterface;
use Velo\Router\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Exceptions\MustImplementMiddlewareInterfaceException;

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
         */
        $next = function (HttpRequest $request) use ($route, &$index, &$castedArgs, &$next) {
            if ($index >= $route->getMiddlewaresCount())
                return $this->coreAction($route, $request, $castedArgs);

            $middlewareClass = $route->getMiddleware($index);
            $index++;

            $middlewareInstance = $this->container->get($middlewareClass);

            if (!$middlewareInstance instanceof MiddlewareInterface)
                throw new MustImplementMiddlewareInterfaceException(
                    "Class $middlewareClass must implement MiddlewareInterface!");

            return $middlewareInstance->handle($request, $next);
        };

        return $next($request);
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
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