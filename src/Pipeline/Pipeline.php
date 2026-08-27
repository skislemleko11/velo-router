<?php
declare(strict_types=1);

namespace Velo\Router\Pipeline;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Velo\Http\Request;
use Velo\Http\Responses\Response;
use Velo\Router\Middlewares\MiddlewareInterface;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MiddlewareNotFoundException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Route\Route;

/**
 * Executes middleware chains.
 */
readonly class Pipeline
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * Main, universal method running the given chain of middlewares. Used for the global pipeline.
     *
     * @param list<MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable> $middlewares
     * If it's a callable, it must return an instance of MiddlewareInterface - it must be a factory function.
     *
     * @throws ContainerExceptionInterface
     * @throws MustImplementMiddlewareInterfaceException
     */
    public function executeMiddlewaresChain(Request $request, array $middlewares, callable $destination): Response
    {
        $index = 0;

        /**
         * @throws NotFoundExceptionInterface
         * @throws ContainerExceptionInterface
         * @throws MustImplementMiddlewareInterfaceException
         * @throws MiddlewareNotFoundException
         */
        $next = function (Request $request) use (&$index, $middlewares, $destination, &$next) {
            if ($index >= count($middlewares)) {
                return $destination($request);
            }

            [$middlewareInstance, $arguments] = $this->getMiddlewareInstanceAndArguments($middlewares[$index]);

            $index++;

            return $middlewareInstance->handle($request, $next, ...$arguments);
        };

        return $next($request);
    }

    /**
     * @param MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable $middleware
     * If it's a callable, it must return an instance of MiddlewareInterface - it must be a factory function.
     *
     * @return array{0: MiddlewareInterface, 1: list<mixed>} a Middleware instance and an array of arguments.
     *
     * @throws MustImplementMiddlewareInterfaceException
     * @throws ContainerExceptionInterface
     */
    private function getMiddlewareInstanceAndArguments(mixed $middleware): array
    {
        $arguments = [];

        if ($middleware instanceof MiddlewareInterface) {
            $middlewareInstance = $middleware;
        } elseif (is_array($middleware)) {
            $middlewareClass = $middleware[0];
            $arguments = $middleware[1] ?? [];
            $middlewareInstance = $this->container->get($middlewareClass);
        } elseif (is_string($middleware)) {
            $middlewareInstance = $this->container->get($middleware);
        } elseif (is_callable($middleware)) {
            $middlewareInstance = $middleware();
        } else {
            throw new MustImplementMiddlewareInterfaceException(
                'Middleware must implement ' . MiddlewareInterface::class
            );
        }

        if (!$middlewareInstance instanceof MiddlewareInterface) {
            throw new MustImplementMiddlewareInterfaceException(
                'Class ' . $middlewareInstance::class . ' must implement ' . MiddlewareInterface::class . '!'
            );
        }

        return [$middlewareInstance, $arguments];
    }

    /**
     * Method dedicated for Routes' middlewares. It uses the main method.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MustImplementMiddlewareInterfaceException
     */
    public function executeRoutesMiddlewaresChain(Route $route, Request $request, array $castedArgs): Response
    {
        $middlewares = $route->getMiddlewares();
        $destination = fn() => $this->executeControllerAction($route, $castedArgs);

        return $this->executeMiddlewaresChain($request, $middlewares, $destination);
    }

    /**
     * Executes the action of the given route's controller.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     */
    private function executeControllerAction(Route $route, array $castedArgs): Response
    {
        $controllerInstance = $this->container->get($route->controller);
        $result = $controllerInstance->{$route->action}(...$castedArgs);

        if (!$result instanceof Response) {
            throw new ControllerMethodInvalidReturnTypeException(
                'Invalid return type of controller ' . $controllerInstance::class .
                ' function! It must extend ' . Response::class . '.'
            );
        }

        return $result;
    }
}