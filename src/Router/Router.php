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
use ValueError;
use Velo\Http\Request;
use Velo\Http\RequestMethod;
use Velo\Http\Responses\Response;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MiddlewareNotFoundException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\UnexpectedInvalidParameterException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterIntersectionTypeException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterMissingTypeDeclarationException;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterUnionTypeException;
use Velo\Router\Router\Exceptions\MethodNotAllowedException;
use Velo\Router\Router\Exceptions\MissingRequiredArgumentException;
use Velo\Router\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Router\Exceptions\NotFoundControllerMethodException;
use Velo\Router\Router\Exceptions\RouteNotFound;
use Velo\Router\Router\Exceptions\UnableToCacheRoutesException;
use Velo\Router\Router\Exceptions\UnableToLoadRoutesException;

/**
 * Router class, it registers Routes and resolves Requests.
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
    public function get(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::GET, $path, $controller, $action);
    }

    /**
     * Registers Route with POST method.
     */
    public function post(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::POST, $path, $controller, $action);
    }

    /**
     * Registers Route with PUT method.
     */
    public function put(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::PUT, $path, $controller, $action);
    }

    /**
     * Registers Route with PATCH method.
     */
    public function patch(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::PATCH, $path, $controller, $action);
    }

    /**
     * Registers Route with DELETE method.
     */
    public function delete(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::DELETE, $path, $controller, $action);
    }

    /**
     * Registers Route with QUERY method.
     */
    public function query(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::QUERY, $path, $controller, $action);
    }

    /**
     * Registers Route with HEAD method.
     */
    public function head(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::HEAD, $path, $controller, $action);
    }

    /**
     * Registers Route with OPTIONS method.
     */
    public function options(string $path, string $controller, string $action = '__invoke'): Route
    {
        return $this->registerRoute(RequestMethod::OPTIONS, $path, $controller, $action);
    }

    private function registerRoute(
        RequestMethod $requestMethod,
        string        $path,
        string        $controller,
        string        $action
    ): Route
    {
        $route = new Route($requestMethod, $path, $controller, $action);
        $this->routes[$requestMethod->value][$path] = $route;

        return $route;
    }

    /**
     * Resolves the given Request.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MiddlewareNotFoundException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundControllerMethodException
     * @throws RouteNotFound
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ReflectionException
     * @throws MethodNotAllowedException
     * @throws ParameterIntersectionTypeException
     * @throws UnexpectedInvalidParameterException
     * @throws ValueError
     */
    public function resolve(Request $request): Response
    {
        if ($response = $this->findMatch($request)) {
            return $response;
        }

        if ($request->method === RequestMethod::HEAD) {
            return $this->handleHeadRequestIfNotRegistered($request);
        }

        if ($allowedMethods = $this->findAllowedMethods($request)) {
            throw new MethodNotAllowedException($allowedMethods);
        }

        throw new RouteNotFound();
    }

    /**
     * Handles the given HEAD request by cloning it, changing method to GET and handling this request.
     *
     * @throws ContainerExceptionInterface
     * @throws MiddlewareNotFoundException
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MethodNotAllowedException
     * @throws ParameterUnionTypeException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerMethodException
     * @throws NotFoundExceptionInterface
     * @throws UnexpectedInvalidParameterException
     * @throws ParameterIntersectionTypeException
     * @throws NotFoundControllerException
     * @throws ReflectionException
     * @throws RouteNotFound
     * @throws ParameterMissingTypeDeclarationException
     * @throws ValueError
     */
    private function handleHeadRequestIfNotRegistered(Request $request): Response
    {
        $getRequest = clone $request;
        $getRequest->changeMethodFromHeadToGet();

        return $this->resolve($getRequest);
    }

    /**
     * Searchs for a matching Route for the given Request.
     * Calls callAction method if found.
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MiddlewareNotFoundException
     * @throws ParameterUnionTypeException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerMethodException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundControllerException
     * @throws ReflectionException
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterIntersectionTypeException
     * @throws UnexpectedInvalidParameterException
     */
    private function findMatch(Request $request): ?Response
    {
        if ($route = $this->routes[$request->method->value][$request->urlPath] ?? null) {
            return $this->callAction($route, $request);
        }

        foreach ($this->routes[$request->method->value] ?? [] as $route) {
            if (preg_match($route->compiledRegex, $request->urlPath, $matches)) {
                /**
                 * @var array<string, string> $namedArgs
                 */
                $namedArgs = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return $this->callAction($route, $request, $namedArgs);
            }
        }

        return null;
    }

    /**
     * Searchs for allowed methods for the given request.
     * Used when findMatch method fails.
     *
     * @return list<string>
     */
    private function findAllowedMethods(Request $request): array
    {
        $allowedMethods = [];

        foreach ($this->routes as $method => $routes) {
            if ($method !== $request->method->value) {
                if (isset($routes[$request->urlPath])) {
                    $allowedMethods[] = $method;
                    continue;
                }

                foreach ($routes as $route) {
                    if (preg_match($route->compiledRegex, $request->urlPath)) {
                        $allowedMethods[] = $method;
                        continue 2;
                    }
                }
            }
        }

        return $allowedMethods;
    }

    /**
     * Calls the action method of the appropriate controller for the given Route.
     *
     * @param array<string, string> $urlMethodArgs
     *
     * @throws ContainerExceptionInterface
     * @throws ControllerMethodInvalidReturnTypeException
     * @throws MiddlewareNotFoundException
     * @throws MissingRequiredArgumentException
     * @throws MustImplementMiddlewareInterfaceException
     * @throws NotFoundControllerException
     * @throws NotFoundExceptionInterface
     * @throws NotFoundControllerMethodException
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ReflectionException
     * @throws ParameterIntersectionTypeException
     * @throws UnexpectedInvalidParameterException
     */
    private function callAction(Route $route, Request $request, array $urlMethodArgs = []): Response
    {
        if (!class_exists($route->controller)) {
            throw new NotFoundControllerException("The requested controller: $route->controller was not found.");
        }

        if (!method_exists($route->controller, $route->action)) {
            throw new NotFoundControllerMethodException("The requested method: $route->controller::$route->action was not found.");
        }

        $castedArgs = $this->castMethodsArgsAndAddRequestToThemIfNeeded(
            className: $route->controller,
            methodName: $route->action,
            args: $urlMethodArgs,
            request: $request
        );

        return $this->pipeline->executeRoutesMiddlewaresChain($route, $request, $castedArgs);
    }

    /**
     * Casts the given arguments for the given controller class name and method name.
     *
     * @param array<string, string> $args
     *
     * @return list<mixed>
     *
     * @throws MissingRequiredArgumentException
     * @throws ParameterMissingTypeDeclarationException
     * @throws ParameterUnionTypeException
     * @throws ParameterIntersectionTypeException
     * @throws UnexpectedInvalidParameterException
     * @throws ReflectionException
     */
    private function castMethodsArgsAndAddRequestToThemIfNeeded(
        string  $className,
        string  $methodName,
        array   $args,
        Request $request
    ): array
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
                $typeName = $paramType->getName();

                if ($typeName === Request::class) {
                    $castedArgs[] = $request;
                } elseif (isset($args[$paramName])) {
                    $value = $args[$paramName];

                    if ($paramType->isBuiltin()) {
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
                throw new UnexpectedInvalidParameterException(
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