<?php
declare(strict_types=1);

namespace Velo\Router;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Velo\Http\HttpRequest;
use Velo\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Exceptions\NotFoundMethodException;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\ControllerMethodInvalidReturnTypeException;


class Router
{
    private(set) array $routes = [];

    public function __construct(private readonly ContainerInterface $container)
    {

    }

    public function get(string $path, string $controller, string $action): void
    {
        $this->routes['GET'][$path] = [$controller, $action];
    }

    public function post(string $path, string $controller, string $action): void
    {
        $this->routes['POST'][$path] = [$controller, $action];
    }

    public function resolve(HttpRequest $request): HttpResponse
    {
        $path = parse_url($request->url, PHP_URL_PATH);
        $handler = $this->routes[$request->method][$path] ?? null;

        if ($handler)
            return $this->callAction($handler[0], $handler[1], $request);

        foreach ($this->routes[$request->method] ?? [] as $routePath => [$controller, $action]) {
            $pattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+}/', '([^/]+)', $routePath) . '$#';

            if (preg_match($pattern, $path, $matches)) {
                // Deleting the 1st element, cuz it stores the whole path
                array_shift($matches);

                return $this->callAction($controller, $action, $request, $matches);
            }
        }

        throw new PageNotFoundException();
    }

    private function callAction(string $controllerClass, string $action, HttpRequest $request, array $getArgs = []): HttpResponse
    {
        if (!class_exists($controllerClass))
            throw new NotFoundControllerException();

        if (!method_exists($controllerClass, $action))
            throw new NotFoundMethodException();

        $controllerInstance = $this->getController($controllerClass);

        $castedArgs = $this->castMethodsArgs($controllerClass, $action, $getArgs);

        $response = $controllerInstance->$action($request, ...$castedArgs);

        if (!$response instanceof HttpResponse)
            throw new ControllerMethodInvalidReturnTypeException(
                sprintf(
                    'Controller action %s::%s() must return an instance of Velo\Http\HttpResponse, %s returned.',
                    get_class($controllerInstance),
                    $action,
                    get_debug_type($response)
                )
            );

        return $response;
    }

    private function getController(string $controllerClass): object
    {
        return $this->container->get($controllerClass);
    }

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