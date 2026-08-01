<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Velo\Container\Container;
use Velo\Controllers\Controller;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Router\Middlewares\MiddlewareInterface;
use Velo\Router\PathResolver\PathResolver;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Router;
use Velo\Router\Router\Exceptions\MissingRequiredArgumentException;
use ReflectionClass;

class RouterTest extends TestCase
{
    protected Container $container;
    protected Router $router;
    protected PathResolver $pathResolver;
    protected Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pathResolver = new PathResolver(
            basePath: '/',
            publicPath: '/public/',
            viewsPath: '/views/',
            error403Path: null,
            error404Path: '/views/error404.php',
            error500Path: '/views/error500.php',
        );
        $this->container = new Container();
        $this->container->set(PathResolver::class, fn() => $this->pathResolver);
        $this->container->set(ContainerInterface::class, fn() => $this->container);

        // Rejestrujemy Pipeline w kontenerze, aby Router mógł go pobrać lub przekazujemy bezpośrednio
        $this->pipeline = new Pipeline($this->container);
        $this->container->set(Pipeline::class, fn() => $this->pipeline);

        $this->router = new Router($this->pipeline);
    }

    private function getProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        return $reflection->getProperty($property)
            ->getValue($object);
    }

    #[Test]
    public function it_registers_a_get_route(): void
    {
        $route = $this->router->get('/users', 'UserController', 'index');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->requestMethod);
        $this->assertSame('/users', $route->path);
        $this->assertSame('UserController', $route->controller);
        $this->assertSame('index', $route->action);

        $this->assertSame($route, $this->getProperty($this->router, 'routes')['GET']['/users']);
    }

    #[Test]
    public function it_registers_a_post_route(): void
    {
        $route = $this->router->post('/users', 'UserController', 'create');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('POST', $route->requestMethod);
        $this->assertSame($route, $this->getProperty($this->router, 'routes')['POST']['/users']);
    }

    #[Test]
    public function it_resolves_a_simple_route(): void
    {
        FakeController::$wasCalled = 0;
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/', FakeController::class, 'index');
        $request = new HttpRequest('/', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
    }

    #[Test]
    public function it_prefers_exact_routes_over_parameterized_routes(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$indexCalls = 0;
        FakeController::$paramsCalls = 0;

        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/users/me', FakeController::class, 'index');
        $this->router->get('/users/{id}', FakeController::class, 'actionWithParams');

        $request = new HttpRequest('/users/me', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$indexCalls);
        $this->assertSame(0, FakeController::$paramsCalls);
    }

    #[Test]
    public function it_resolves_a_route_with_parameters_and_casts_them(): void
    {
        FakeController::$wasCalled = 0;
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/users/{id}/{sth}', FakeController::class, 'actionWithParams');

        // Przekazujemy stringi w adresie URL ('5' oraz '100')
        $request = new HttpRequest('/users/5/100', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(5, FakeController::$lastArgs['id']);
        $this->assertSame(100, FakeController::$lastArgs['sth']);
    }

    #[Test]
    public function it_casts_nullable_and_builtin_parameters(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/flags/{active}/{ratio}', FakeController::class, 'actionWithNullableAndTyped');

        $request = new HttpRequest('/flags/1/2.5', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(['label' => null, 'active' => true, 'ratio' => 2.5], FakeController::$lastArgs);
    }

    #[Test]
    public function it_uses_default_values_for_missing_arguments(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/reports/{id}', FakeController::class, 'actionWithDefaultValue');

        $request = new HttpRequest('/reports/7', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(['id' => 7, 'type' => 'default'], FakeController::$lastArgs);
    }

    #[Test]
    public function it_throws_missing_required_argument_exception_when_route_is_incomplete(): void
    {
        $this->expectException(MissingRequiredArgumentException::class);
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/users/{id}', FakeController::class, 'actionWithParams');
        $request = new HttpRequest('/users/5', 'GET');

        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_page_not_found_exception(): void
    {
        $this->expectException(PageNotFoundException::class);
        $request = new HttpRequest('/users', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_controller_method_invalid_return_type_exception(): void
    {
        $this->expectException(ControllerMethodInvalidReturnTypeException::class);
        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));

        $this->router->get('/', FakeController::class, 'invalidReturnType');
        $request = new HttpRequest('/', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_allows_fluent_middleware_registration_on_route(): void
    {
        $route = $this->router->get('/admin', FakeController::class, 'index')
            ->addMiddleware('SomeMiddleware')
            ->addMiddleware('AnotherMiddleware');

        $this->assertSame('SomeMiddleware', $route->getMiddleware(0));
        $this->assertSame('AnotherMiddleware', $route->getMiddleware(1));
    }

    #[Test]
    public function it_executes_middleware_chain_before_reaching_controller(): void
    {
        FakeController::$wasCalled = 0;
        FakeMiddleware::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));
        $this->container->set(FakeMiddleware::class, fn() => new FakeMiddleware());

        $this->router->get('/dashboard', FakeController::class, 'index')
            ->addMiddleware(FakeMiddleware::class);

        $request = new HttpRequest('/dashboard', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeMiddleware::$wasCalled);
        $this->assertSame(1, FakeController::$wasCalled);
    }

    #[Test]
    public function middleware_can_short_circuit_and_stop_controller_execution(): void
    {
        FakeController::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));
        $this->container->set(StoppingMiddleware::class, fn() => new StoppingMiddleware());

        $this->router->get('/protected', FakeController::class, 'index')
            ->addMiddleware(StoppingMiddleware::class);

        $request = new HttpRequest('/protected', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(403, $result->statusCode);
        $this->assertSame(0, FakeController::$wasCalled);
    }

    #[Test]
    public function it_returns_false_when_loading_a_missing_cache_file(): void
    {
        $missingFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'velo-router-missing-' . uniqid('', true) . '.php';

        $this->assertFalse($this->router->loadFromCache($missingFile));
    }

    #[Test]
    public function it_caches_routes_and_restores_them_from_a_file(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];
        FakeMiddleware::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController($this->pathResolver));
        $this->container->set(FakeMiddleware::class, fn() => new FakeMiddleware());

        $this->router->get('/cached/{id}', FakeController::class, 'actionWithDefaultValue')
            ->addMiddleware(FakeMiddleware::class);

        $cacheFile = tempnam(sys_get_temp_dir(), 'velo-router-');
        self::assertNotFalse($cacheFile);

        try {
            $this->router->cacheRoutes($cacheFile);

            $this->assertFileExists($cacheFile);
            $this->assertStringContainsString('FakeController', (string) file_get_contents($cacheFile));
            $this->assertStringContainsString('FakeMiddleware', (string) file_get_contents($cacheFile));

            $cachedRouter = new Router($this->pipeline);
            $this->assertTrue($cachedRouter->loadFromCache($cacheFile));

            $request = new HttpRequest('/cached/42', 'GET');
            $result = $cachedRouter->resolve($request);

            $this->assertInstanceOf(HttpResponse::class, $result);
            $this->assertSame(1, FakeMiddleware::$wasCalled);
            $this->assertSame(1, FakeController::$wasCalled);
            $this->assertSame(['id' => 42, 'type' => 'default'], FakeController::$lastArgs);
        } finally {
            @unlink($cacheFile);
        }
    }
}

class FakeController extends Controller
{
    public static int $wasCalled = 0;
    public static int $indexCalls = 0;
    public static int $paramsCalls = 0;
    public static array $lastArgs = [];

    public function index(HttpRequest $request): HttpResponse
    {
        self::$wasCalled++;
        self::$indexCalls++;
        return new HttpResponse(null, 200);
    }

    public function actionWithParams(HttpRequest $request, int $id, int $sth): HttpResponse
    {
        self::$wasCalled++;
        self::$paramsCalls++;
        self::$lastArgs = ['id' => $id, 'sth' => $sth];
        return new HttpResponse(null, 200);
    }

    public function actionWithDefaultValue(HttpRequest $request, int $id, string $type = 'default'): HttpResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['id' => $id, 'type' => $type];
        return new HttpResponse(null, 200);
    }

    public function actionWithNullableAndTyped(HttpRequest $request, ?string $label, bool $active, float $ratio): HttpResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['label' => $label, 'active' => $active, 'ratio' => $ratio];
        return new HttpResponse(null, 200);
    }

    /** @phpstan-ignore-next-line */
    public function invalidReturnType(HttpRequest $request): string
    {
        return 'string';
    }
}

class FakeMiddleware implements MiddlewareInterface
{
    public static int $wasCalled = 0;

    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        self::$wasCalled++;
        return $next($request);
    }
}

class StoppingMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        return new HttpResponse(null, 403);
    }
}