<?php
declare(strict_types=1);

namespace Velo\Router\Route;

use Velo\Http\RequestMethod;
use Velo\Router\Middlewares\AddMiddlewaresTrait;
use Velo\Router\Middlewares\MiddlewareInterface;

/**
 * Represnts Route, it's registered in Router Class.
 */
class Route
{
    use AddMiddlewaresTrait;

    public readonly string $compiledRegex;

    public function __construct(
        public readonly RequestMethod $requestMethod,
        public readonly string        $path,
        public readonly string        $controller,
        public readonly string        $action,
        ?string                       $compiledRegex = null
    )
    {
        if ($compiledRegex === null) {
            $this->compiledRegex = '#^' . preg_replace('/\{([a-zA-Z0-9_]+)}/', '(?P<$1>[^/]+)', $path) . '$#';
        } else {
            $this->compiledRegex = $compiledRegex;
        }
    }

    /**
     * Gets the middleware at the given index. Returns null if it's not set.
     *
     * @return string|array|MiddlewareInterface{0: string, 1?: list<mixed>}|callable|null
     */
    public function getMiddleware(int $index): MiddlewareInterface|string|array|callable|null
    {
        return $this->middlewares[$index] ?? null;
    }

    /**
     * Getter method for middlewares array.
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Returns the count (length) of middlewares array.
     */
    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }

    public static function __set_state(array $array): object
    {
        $route = new self(
            requestMethod: $array['requestMethod'],
            path: $array['path'],
            controller: $array['controller'],
            action: $array['action'],
            compiledRegex: $array['compiledRegex']
        );

        $route->middlewares = $array['middlewares'];

        return $route;
    }
}