<?php
declare(strict_types=1);

namespace Velo\Router\Router;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MiddlewareNotFoundException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\InvalidParameterException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterIntersectionTypeException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterMissingTypeDeclarationException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterUnionTypeException;
use Velo\Router\Router\Exceptions\MissingRequiredArgumentException;
use Velo\Router\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Router\Exceptions\NotFoundMethodException;
use Velo\Router\Router\Exceptions\UnableToCacheRoutesException;
use Velo\Router\Router\Exceptions\UnableToLoadRoutesException;

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
     * Resolves the given HttpRequest and calls callAction method to the appropriate controller method.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws InvalidParameterException
     * @throws MiddlewareNotFoundException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws PageNotFoundException
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ReflectionException
     */
    public function resolve(HttpRequest $request): HttpResponse
    {
        $route = $this->routes[$request->requestMethod][$request->urlPath] ?? null;

        if ($route) {
            return $this->callAction($route, $request);
        }

        foreach ($this->routes[$request->requestMethod] ?? [] as $route) {
            if (preg_match($route->compiledRegex, $request->urlPath, $matches)) {
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
     * @throws InvalidParameterException
     * @throws MiddlewareNotFoundException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundMethodException
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ReflectionException
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
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ParameterIntersectionTypeException
     * @throws InvalidParameterException
     */
    private function castMethodsArgs(string $className, string $methodName, array $args): array
    {
        $reflection = new ReflectionMethod($className, $methodName);
        $reflectionParams = $reflection->getParameters();

        $castedArgs = [];

        foreach ($reflectionParams as $param) {
            $paramType = $param->getType();
            $paramName = $param->getName();

            if (!$paramType) {
                throw new ParameterMissingTypeDeclarationException(
                    "Parameter $paramName of $className::$methodName is missing a type declaration!"
                );
            }

            if ($paramType instanceof ReflectionNamedType) {
                if ($paramType->getName() === HttpRequest::class) {
                    continue;
                }

                if (isset($args[$paramName])) {
                    $value = $args[$paramName];

                    if ($paramType->isBuiltin()) {
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
            } elseif ($paramType instanceof ReflectionUnionType) {
                throw new ParameterUnionTypeException(
                    "Parameter $paramName of $className::$methodName cannot be of a union type!"
                );
            } elseif ($paramType instanceof ReflectionIntersectionType) {
                throw new ParameterIntersectionTypeException(
                    "Parameter $paramName of $className::$methodName cannot be of an intersection type!"
                );
            } else {
                // Probably it's not reachable in current(8.5) PHP, but I'm leaving it in case of future changes or bugs
                throw new InvalidParameterException(
                    "Parameter $paramName of $className::$methodName is of an invalid type!"
                );
            }
        }

        return $castedArgs;
    }

    /**
     * Caches Routes to the given filePath.
     *
     * @throws UnableToCacheRoutesException If unable to cache routes
     */
    public function cacheRoutes(string $filePath): void
    {
        $dir = dirname($filePath);

        if (!((is_file($filePath) && is_writable($filePath)) || (!file_exists($filePath) && is_writable($dir)))) {
            throw new UnableToCacheRoutesException(
                "Unable to cache routes to the given file path: $filePath!"
            );
        }

        $content = '<?php' . PHP_EOL . '// This was generated automatically. Do not edit it!' . PHP_EOL .
            'return ' . var_export($this->routes, true) . ';';

        if (file_put_contents($filePath, $content) === false) {
            throw new UnableToCacheRoutesException(
                "Unable to cache routes to the given file path: $filePath!"
            );
        }
    }

    private function canReadFile(string $filePath): bool
    {
        return is_file($filePath) && is_readable($filePath);
    }

    // TODO: IT'S NOT A VERY SAFE SOLUTION, SECURE IT SOON!

    /**
     * Loads Routes from a cache file.
     *
     * @param string $filePath Cache file path
     * @return bool False if the file does not exist or the given path is not a file or it's not readable, True if the file is loaded successfully
     */
    public function loadRoutesFromCache(string $filePath): bool
    {
        if (!$this->canReadFile($filePath)) {
            return false;
        }

        $this->routes = require $filePath;

        return true;
    }

    // TODO: IT'S NOT A VERY SAFE SOLUTION, SECURE IT SOON!
    /**
     * Loads a Routes Registry File. It's meant to be a file where Routes are registered using Router methods.
     *
     * @return bool False if can not read the given filePath, True if the file is loaded successfully
     */
    public function loadRoutesFromRegistryFile(string $filePath): bool
    {
        if (!$this->canReadFile($filePath)) {
            return false;
        }

        $router = $this;

        require $filePath;

        return true;
    }

    /**
     * Loads routes from cache if it exists, otherwise loads from registry file and caches them.
     *
     * @throws UnableToCacheRoutesException
     * @throws UnableToLoadRoutesException
     */
    public function loadRoutesFromCacheIfExistsElseFromRegistryFile(
        string $cachePath,
        string $routesRegistryPath,
        bool   $cacheRoutesIfNotCached = true
    ): void
    {
        if (!$this->loadRoutesFromCache($cachePath)) {
            if ($this->loadRoutesFromRegistryFile($routesRegistryPath)) {
                if ($cacheRoutesIfNotCached) {
                    $this->cacheRoutes($cachePath);
                }
            } else {
                throw new UnableToLoadRoutesException(
                    "Router was unable to load routes either from cache file: $cachePath or registry file: $routesRegistryPath."
                );
            }
        }
    }
}