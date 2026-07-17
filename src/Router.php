<?php
declare(strict_types=1);

namespace Velo\Router;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Velo\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Exceptions\NotFoundMethodException;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Http\HttpResponse;

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

    public function resolve(string $url, string $requestMethod): HttpResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $handler = $this->routes[$requestMethod][$path] ?? null;

        if ($handler)
            return $this->callAction(...$handler);

        foreach ($this->routes[$requestMethod] ?? [] as $routePath => [$controller, $action]) {
            $pattern = '#^' . preg_replace('/\{[a-zA-Z0-9_]+}/', '([^/]+)', $routePath) . '$#';

            if (preg_match($pattern, $path, $matches)) {
                // Deleting the 1st element, cuz it stores the whole path
                array_shift($matches);

                return $this->callAction($controller, $action, $matches);
            }
        }

        throw new PageNotFoundException();
    }

    private function callAction(string $controllerClass, string $action, array $args = []): HttpResponse
    {
        if (!class_exists($controllerClass))
            throw new NotFoundControllerException();

        if (!method_exists($controllerClass, $action))
            throw new NotFoundMethodException();

        $controllerInstance = $this->createController($controllerClass);

        $castedArgs = $this->castMethodsArgs($controllerClass, $action, $args);

        return $controllerInstance->$action(...$castedArgs);
    }

    private function createController(string $controllerClass): object
    {
        return $this->container->get($controllerClass);
    }

    private function castMethodsArgs(string $className, string $functionName, array $args): array
    {
        $reflection = new ReflectionMethod($className, $functionName);
        $reflectionParams = $reflection->getParameters();

        $castedArgs = [];

        foreach ($reflectionParams as $index => $param) {
            if (isset($args[$index])) {
                $value = $args[$index];
                $type = $param->getType();

                if ($type && $type->isBuiltin()) {
                    $typeName = $type->getName();

                    settype($value, $typeName);
                }

                $castedArgs[] = $value;
            }
        }

        return $castedArgs;
    }
}