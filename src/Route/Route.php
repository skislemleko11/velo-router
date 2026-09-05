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
     * @return string|array{0: string, 1?: list<mixed>}|MiddlewareInterface|callable|null
     */
    public function getMiddleware(int $index): MiddlewareInterface|string|array|callable|null
    {
        return $this->middlewares[$index] ?? null;
    }

    /**
     * @return list<MiddlewareInterface|string|array{0: string, 1?: list<mixed>}|callable>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getMiddlewaresCount(): int
    {
        return count($this->middlewares);
    }

    /**
     * @param array<string, mixed> $array
     */
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