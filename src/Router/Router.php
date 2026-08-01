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
use Velo\Router\Router\Exceptions\MissingRequiredArgumentException;
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
     * @throws MissingRequiredArgumentException
     */
    public function resolve(HttpRequest $request): HttpResponse
    {
        $route = $this->routes[$request->method][$request->url] ?? null;

        if ($route) {
            return $this->callAction($route, $request);
        }

        foreach ($this->routes[$request->method] ?? [] as $route) {
            if (preg_match($route->compiledRegex, $request->url, $matches)) {
                $namedArgs = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->callAction($route, $request, $namedArgs);
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
     * @throws MissingRequiredArgumentException
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
     * @throws MissingRequiredArgumentException
     */
    private function castMethodsArgs(string $className, string $methodName, array $args): array
    {
        $reflection = new ReflectionMethod($className, $methodName);
        $reflectionParams = $reflection->getParameters();

        $castedArgs = [];

        foreach ($reflectionParams as $param) {
            $paramType = $param->getType();
            $paramName = $param->getName();

            if ($paramType && $paramType->getName() === HttpRequest::class) {
                continue;
            }

            if (isset($args[$paramName])) {
                $value = $args[$paramName];

                if ($paramType && $paramType->isBuiltin()) {
                    $typeName = $paramType->getName();
                    settype($value, $typeName);
                }

                $castedArgs[] = $value;
            } elseif ($param->isDefaultValueAvailable()) {
                $castedArgs[] = $param->getDefaultValue();
            } elseif ($paramType->allowsNull()) {
                $castedArgs[] = null;
            } else {
                throw new MissingRequiredArgumentException(
                  "Missing required argument $paramName for method $className::$methodName()"
                );
            }
        }

        return $castedArgs;
    }

    public function cacheRoutes(string $filePath): void
    {
        $content = '<?php' . PHP_EOL . '// This was generated automatically. Do not edit it!' . PHP_EOL .
            'return ' . var_export($this->routes, true) . ';';

        file_put_contents($filePath, $content);
    }

    public function loadFromCache(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $this->routes = require $filePath;

        return true;
    }
}