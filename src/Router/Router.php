<?php
declare(strict_types=1);

namespace Velo\Router\Router;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use ReflectionMethod;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MiddlewareNotFoundException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Router\Exceptions\NotFoundMethodException;

/**
 * Router class, it registers Routes and resolves HttpRequests.
 */
class Router
{
    /**
     * @var array<string, array<string, Route>>
     */
    private array $routes = [];

    public function __construct(
        private readonly Pipeline $pipeline,
    )
    {
    }

    /**
     * Registers Route with GET method.
     */
    public function get(string $path, string $controller, string $action): Route
    {
        return $this->registerRoute('GET', $path, $controller, $action);
    }

    /**
     * Registers Route with POST method.
     */
    public function post(string $path, string $controller, string $action): Route
    {
        return $this->registerRoute('POST', $path, $controller, $action);
    }

    /**
     * Registers a Route with the given method.
     */
    private function registerRoute(string $method, string $path, string $controller, string $action): Route
    {
        $route = new Route($method, $path, $controller, $action);
        $this->routes[$method][$path] = $route;
        return $route;
    }

    /**
     * Resolves the given HttpReqest and calls callAction method to the appropriate controller method.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws PageNotFoundException
     * @throws ReflectionException
     * @throws MiddlewareNotFoundException
     */
    public function resolve(HttpRequest $request): HttpResponse
    {
        $route = $this->routes[$request->method][$request->url] ?? null;

        if ($route) {
            return $this->callAction($route, $request);
        }

        foreach ($this->routes[$request->method] ?? [] as $routePath => $route) {
            $pattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+}/', '([^/]+)', $routePath) . '$#';

            if (preg_match($pattern, $request->url, $matches)) {
                // Deleting the 1st element, cuz it stores the whole path
                array_shift($matches);

                return $this->callAction($route, $request, $matches);
            }
        }

        throw new PageNotFoundException();
    }

    /**
     * Calls the action method of the appropriate controller for the given Route.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws ReflectionException
     * @throws MiddlewareNotFoundException
     */
    private function callAction(Route $route, HttpRequest $request, array $getMethodArgs = []): HttpResponse
    {
        if (!class_exists($route->controller)) {
            throw new NotFoundControllerException();
        }

        if (!method_exists($route->controller, $route->action)) {
            throw new NotFoundMethodException();
        }

        $castedArgs = $this->castMethodsArgs($route->controller, $route->action, $getMethodArgs);

        return $this->pipeline->executeRoutesMiddlewaresChain($route, $request, $castedArgs);
    }

    /**
     * Casts the given arguments for the given controller class name and method name.
     *
     * @throws ReflectionException
     */
    private function castMethodsArgs(string $className, string $methodName, array $args): array
    {
        $reflection = new ReflectionMethod($className, $methodName);
        $reflectionParams = $reflection->getParameters();

        $castedArgs = [];
        $argsIndex = 0;

        foreach ($reflectionParams as $param) {
            $type = $param->getType();

            if ($type && $type->getName() === HttpRequest::class) {
                continue;
            }

            if (isset($args[$argsIndex])) {
                $value = $args[$argsIndex];

                if ($type && $type->isBuiltin()) {
                    $typeName = $type->getName();

                    settype($value, $typeName);
                }

                $castedArgs[] = $value;
                $argsIndex++;
            }
        }

        return $castedArgs;
    }
}