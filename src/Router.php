<?php
declare(strict_types=1);

namespace Velo\Router;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use ReflectionMethod;
use Velo\Http\HttpRequest;
use Velo\Router\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Exceptions\NotFoundMethodException;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\ControllerMethodInvalidReturnTypeException;


class Router
{
    /**
     * @var array<string, array<string, Route>>
     */
    private(set) array $routes = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Pipeline $pipeline,
    )
    {
    }

    public function get(string $path, string $controller, string $action): Route
    {
        return $this->registerRoute('GET', $path, $controller, $action);
    }

    public function post(string $path, string $controller, string $action): Route
    {
        return $this->registerRoute('POST', $path, $controller, $action);
    }

    private function registerRoute(string $method, string $path, string $controller, string $action): Route
    {
        $route = new Route($method, $path, $controller, $action);
        $this->routes[$method][$path] = $route;
        return $route;
    }

    /**
     * @param HttpRequest $request
     * @return HttpResponse
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws PageNotFoundException
     * @throws ReflectionException
     */
    public function resolve(HttpRequest $request): HttpResponse
    {
        $path = parse_url($request->url, PHP_URL_PATH);
        $route = $this->routes[$request->method][$path] ?? null;

        if ($route)
            return $this->callAction($route, $request);

        foreach ($this->routes[$request->method] ?? [] as $routePath => $route) {
            $pattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+}/', '([^/]+)', $routePath) . '$#';

            if (preg_match($pattern, $path, $matches)) {
                // Deleting the 1st element, cuz it stores the whole path
                array_shift($matches);

                return $this->callAction($route, $request, $matches);
            }
        }

        throw new PageNotFoundException();
    }

    /**
     * @param Route $route
     * @param HttpRequest $request
     * @param array $getArgs
     * @return HttpResponse
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws ReflectionException
     */
    private function callAction(Route $route, HttpRequest $request, array $getArgs = []): HttpResponse
    {
        if (!class_exists($route->controller))
            throw new NotFoundControllerException();

        if (!method_exists($route->controller, $route->action))
            throw new NotFoundMethodException();

        $controllerInstance = $this->getController($route->controller);

        $castedArgs = $this->castMethodsArgs($route->controller, $route->action, $getArgs);

        $response = $this->pipeline->executeMiddlewareChain($route, $request, $castedArgs);

        if (!$response instanceof HttpResponse)
            throw new ControllerMethodInvalidReturnTypeException(
                sprintf(
                    'Controller action %s::%s() must return an instance of Velo\Http\HttpResponse, %s returned.',
                    get_class($controllerInstance),
                    $route->action,
                    get_debug_type($response)
                )
            );

        return $response;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function getController(string $controllerClass): object
    {
        return $this->container->get($controllerClass);
    }

    /**
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

            if ($type && $type->getName() === HttpRequest::class)
                continue;

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