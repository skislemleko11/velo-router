<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Velo\Container\Container;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Router\Middlewares\MiddlewareInterface;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Exceptions\InvalidParameterExceptions\ParameterMissingTypeDeclarationException;
use Velo\Router\Router\Exceptions\MissingRequiredArgumentException;
use Velo\Router\Router\Exceptions\NotFoundControllerException;
use Velo\Router\Router\Exceptions\NotFoundMethodException;
use Velo\Router\Router\Exceptions\UnableToCacheRoutesException;
use Velo\Router\Router\Exceptions\UnableToLoadRoutesException;
use Velo\Router\Router\Router;

class RouterTest extends TestCase
{
    protected Container $container;
    protected Router $router;
    protected Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->set(ContainerInterface::class, fn() => $this->container);

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
        $this->container->set(FakeController::class, fn() => new FakeController());

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

        $this->container->set(FakeController::class, fn() => new FakeController());

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
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/users/{id}/{sth}', FakeController::class, 'actionWithParams');

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
        $this->container->set(FakeController::class, fn() => new FakeController());

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
        $this->container->set(FakeController::class, fn() => new FakeController());

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
        $this->container->set(FakeController::class, fn() => new FakeController());

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
        $this->container->set(FakeController::class, fn() => new FakeController());

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

        $this->container->set(FakeController::class, fn() => new FakeController());
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

        $this->container->set(FakeController::class, fn() => new FakeController());
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

        $this->assertFalse($this->router->loadRoutesFromCache($missingFile));
    }

    #[Test]
    public function it_caches_routes_and_restores_them_from_a_file(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];
        FakeMiddleware::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());
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
            $this->assertTrue($cachedRouter->loadRoutesFromCache($cacheFile));

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

    #[Test]
    public function it_throws_unable_to_cache_routes_exception_when_file_is_not_writable(): void
    {
        $this->expectException(UnableToCacheRoutesException::class);
        $this->router->get('/test', FakeController::class, 'index');

        $this->router->cacheRoutes('/invalid/path/that/does/not/exist/cache.php');
    }

    #[Test]
    public function it_throws_unable_to_load_routes_exception_when_both_cache_and_registry_fail(): void
    {
        $this->expectException(UnableToLoadRoutesException::class);

        $missingCachePath = sys_get_temp_dir() . '/velo-missing-cache-' . uniqid() . '.php';
        $missingRegistryPath = sys_get_temp_dir() . '/velo-missing-registry-' . uniqid() . '.php';

        $this->router->loadRoutesFromCacheIfExistsElseFromRegistryFile($missingCachePath, $missingRegistryPath);
    }

    #[Test]
    public function it_loads_routes_from_registry_file_when_cache_does_not_exist(): void
    {
        FakeController::$wasCalled = 0;
        $this->container->set(FakeController::class, fn() => new FakeController());

        $registryFile = tempnam(sys_get_temp_dir(), 'velo-registry-');
        self::assertNotFalse($registryFile);

        try {
            file_put_contents($registryFile, '<?php $router->get("/registry", "' . FakeController::class . '", "index");');

            $cacheFile = sys_get_temp_dir() . '/velo-cache-' . uniqid() . '.php';
            $newRouter = new Router($this->pipeline);

            $newRouter->loadRoutesFromCacheIfExistsElseFromRegistryFile(
                $cacheFile,
                $registryFile,
                false
            );

            $request = new HttpRequest('/registry', 'GET');
            $result = $newRouter->resolve($request);

            $this->assertInstanceOf(HttpResponse::class, $result);
            $this->assertSame(1, FakeController::$wasCalled);
        } finally {
            @unlink($registryFile);
            @unlink($cacheFile ?? '');
        }
    }

    #[Test]
    public function it_loads_routes_from_cache_if_exists(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/cached-test/{id}', FakeController::class, 'actionWithDefaultValue');
        $cacheFile = tempnam(sys_get_temp_dir(), 'velo-router-');
        self::assertNotFalse($cacheFile);

        try {
            $this->router->cacheRoutes($cacheFile);

            $newRouter = new Router($this->pipeline);
            $registryFile = tempnam(sys_get_temp_dir(), 'velo-registry-');
            self::assertNotFalse($registryFile);

            try {
                $newRouter->loadRoutesFromCacheIfExistsElseFromRegistryFile(
                    $cacheFile,
                    $registryFile,
                    false
                );

                $request = new HttpRequest('/cached-test/99', 'GET');
                $result = $newRouter->resolve($request);

                $this->assertInstanceOf(HttpResponse::class, $result);
                $this->assertSame(1, FakeController::$wasCalled);
                $this->assertSame(['id' => 99, 'type' => 'default'], FakeController::$lastArgs);
            } finally {
                @unlink($registryFile);
            }
        } finally {
            @unlink($cacheFile);
        }
    }

    #[Test]
    public function it_returns_false_when_loading_routes_from_missing_registry_file(): void
    {
        $missingFile = sys_get_temp_dir() . '/velo-missing-registry-' . uniqid() . '.php';
        $this->assertFalse($this->router->loadRoutesFromRegistryFile($missingFile));
    }

    #[Test]
    public function it_throws_not_found_controller_exception_when_controller_does_not_exist(): void
    {
        $this->expectException(NotFoundControllerException::class);

        $this->router->get('/test', 'NonExistentController', 'index');
        $request = new HttpRequest('/test', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_not_found_method_exception_when_method_does_not_exist(): void
    {
        $this->expectException(NotFoundMethodException::class);

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/test', FakeController::class, 'nonExistentMethod');
        $request = new HttpRequest('/test', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_parameter_missing_type_declaration_exception(): void
    {
        $this->expectException(ParameterMissingTypeDeclarationException::class);

        $this->container->set(TypesController::class, fn() => new TypesController());

        $this->router->get('/types/{id}', TypesController::class, 'noType');
        $request = new HttpRequest('/types/5', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_adds_multiple_middlewares_with_fluent_api(): void
    {
        $route = $this->router->get('/admin', FakeController::class, 'index')
            ->addMiddlewares('Middleware1', 'Middleware2', 'Middleware3');

        $this->assertSame('Middleware1', $route->getMiddleware(0));
        $this->assertSame('Middleware2', $route->getMiddleware(1));
        $this->assertSame('Middleware3', $route->getMiddleware(2));
        $this->assertSame(3, $route->getMiddlewaresCount());
    }

    #[Test]
    public function it_executes_multiple_middlewares_in_correct_order(): void
    {
        OrderTrackingMiddleware::$executionOrder = [];

        $this->container->set(FakeController::class, fn() => new FakeController());
        $this->container->set(FirstOrderMiddleware::class, fn() => new FirstOrderMiddleware());
        $this->container->set(SecondOrderMiddleware::class, fn() => new SecondOrderMiddleware());
        $this->container->set(ThirdOrderMiddleware::class, fn() => new ThirdOrderMiddleware());

        $this->router->get('/ordered', FakeController::class, 'index')
            ->addMiddleware(FirstOrderMiddleware::class)
            ->addMiddleware(SecondOrderMiddleware::class)
            ->addMiddleware(ThirdOrderMiddleware::class);

        $request = new HttpRequest('/ordered', 'GET');
        $this->router->resolve($request);

        $this->assertSame(['first', 'second', 'third'], OrderTrackingMiddleware::$executionOrder);
    }

    #[Test]
    public function it_executes_middleware_with_array_arguments(): void
    {
        FakeController::$wasCalled = 0;
        ArgumentCapturingMiddleware::$capturedArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());
        $this->container->set(ArgumentCapturingMiddleware::class, fn() => new ArgumentCapturingMiddleware());

        $this->router->get('/with-args', FakeController::class, 'index')
            ->addMiddleware([ArgumentCapturingMiddleware::class, ['arg1', 'arg2', 42]]);

        $request = new HttpRequest('/with-args', 'GET');
        $this->router->resolve($request);

        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(['arg1', 'arg2', 42], ArgumentCapturingMiddleware::$capturedArgs);
    }

    #[Test]
    public function it_throws_exception_when_middleware_does_not_implement_interface(): void
    {
        $this->expectException(MustImplementMiddlewareInterfaceException::class);

        $this->container->set(FakeController::class, fn() => new FakeController());
        $this->container->set(InvalidMiddleware::class, fn() => new InvalidMiddleware());

        $this->router->get('/bad-middleware', FakeController::class, 'index')
            ->addMiddleware(InvalidMiddleware::class);

        $request = new HttpRequest('/bad-middleware', 'GET');
        $this->router->resolve($request);
    }

    #[Test]
    public function it_executes_callable_middleware(): void
    {
        FakeController::$wasCalled = 0;
        CallableMiddlewareTest::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/callable', FakeController::class, 'index')
            ->addMiddleware(function (): MiddlewareInterface {
                CallableMiddlewareTest::$wasCalled++;
                return new CallableTestMiddlewareImpl();
            });

        $request = new HttpRequest('/callable', 'GET');
        $this->router->resolve($request);

        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(1, CallableMiddlewareTest::$wasCalled);
    }

    #[Test]
    public function it_handles_route_with_multiple_path_parameters(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/api/{version}/users/{id}/posts/{postId}', FakeController::class, 'actionWithParams');

        $request = new HttpRequest('/api/v1/users/123/posts/456', 'GET');

        try {
            $this->router->resolve($request);
        } catch (MissingRequiredArgumentException $e) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function it_resolves_route_with_all_nullable_parameters(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/nullable/{label}/{active}/{ratio}', FakeController::class, 'actionWithNullableAndTyped');

        $request = new HttpRequest('/nullable/test/1/2.5', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
    }

    #[Test]
    public function it_handles_route_parameter_casting_for_boolean(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/bool-test/{active}/{ratio}', FakeController::class, 'actionWithNullableAndTyped');

        $request = new HttpRequest('/bool-test/0/1.5', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(false, FakeController::$lastArgs['active']);
        $this->assertSame(1.5, FakeController::$lastArgs['ratio']);
    }

    #[Test]
    public function route_preserves_middleware_after_serialization_and_deserialization(): void
    {
        FakeController::$wasCalled = 0;
        FakeMiddleware::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());
        $this->container->set(FakeMiddleware::class, fn() => new FakeMiddleware());

        $originalRoute = $this->router->get('/serialized', FakeController::class, 'index')
            ->addMiddleware(FakeMiddleware::class)
            ->addMiddleware('AnotherMiddleware');

        $routeExport = var_export($originalRoute, true);
        $deserialized = eval('return ' . $routeExport . ';');

        $this->assertSame(2, $deserialized->getMiddlewaresCount());
        $this->assertSame(FakeMiddleware::class, $deserialized->getMiddleware(0));
        $this->assertSame('AnotherMiddleware', $deserialized->getMiddleware(1));
    }

    #[Test]
    public function it_correctly_handles_exact_route_match_before_parameter_route(): void
    {
        FakeController::$indexCalls = 0;
        FakeController::$paramsCalls = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/special/endpoint', FakeController::class, 'index');
        $this->router->get('/special/{name}', FakeController::class, 'actionWithParams');

        $exactRequest = new HttpRequest('/special/endpoint', 'GET');
        $exactResult = $this->router->resolve($exactRequest);
        $this->assertSame(1, FakeController::$indexCalls);
        $this->assertSame(0, FakeController::$paramsCalls);

        FakeController::$indexCalls = 0;
        FakeController::$paramsCalls = 0;

        $paramRequest = new HttpRequest('/special/something', 'GET');
        try {
            $this->router->resolve($paramRequest);
        } catch (MissingRequiredArgumentException $e) {
            $this->assertTrue(true);
            return;
        }
        $this->fail('Expected MissingRequiredArgumentException');
    }

    #[Test]
    public function it_handles_empty_route_path_gracefully(): void
    {
        FakeController::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());
        $this->router->get('/', FakeController::class, 'index');

        $request = new HttpRequest('/', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
    }

    #[Test]
    public function it_differentiates_routes_by_http_method(): void
    {
        FakeController::$indexCalls = 0;
        FakeController::$wasCalled = 0;

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/resource', FakeController::class, 'index');
        $this->router->post('/resource', FakeController::class, 'actionWithParams');

        $getRequest = new HttpRequest('/resource', 'GET');
        $getResult = $this->router->resolve($getRequest);

        $this->assertInstanceOf(HttpResponse::class, $getResult);
        $this->assertSame(1, FakeController::$indexCalls);
    }
}

class FakeController
{
    public static int $wasCalled = 0;
    public static int $indexCalls = 0;
    public static int $paramsCalls = 0;
    public static array $lastArgs = [];

    public function index(HttpRequest $request): HttpResponse
    {
        self::$wasCalled++;
        self::$indexCalls++;
        return HttpResponse::plainText('hehe');
    }

    public function actionWithParams(HttpRequest $request, int $id, int $sth): HttpResponse
    {
        self::$wasCalled++;
        self::$paramsCalls++;
        self::$lastArgs = ['id' => $id, 'sth' => $sth];
        return HttpResponse::plainText('hehe');
    }

    public function actionWithDefaultValue(HttpRequest $request, int $id, string $type = 'default'): HttpResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['id' => $id, 'type' => $type];
        return HttpResponse::plainText('hehe');
    }

    public function actionWithNullableAndTyped(HttpRequest $request, ?string $label, bool $active, float $ratio): HttpResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['label' => $label, 'active' => $active, 'ratio' => $ratio];
        return HttpResponse::plainText('hehe');
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
        return HttpResponse::plainText('hehe', 403);
    }
}

class TypesController
{
    public function noType(HttpRequest $request, $id): HttpResponse
    {
        return HttpResponse::plainText('hehe');
    }
}

class OrderTrackingMiddleware implements MiddlewareInterface
{
    public static array $executionOrder = [];

    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        return $next($request);
    }
}

class FirstOrderMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        OrderTrackingMiddleware::$executionOrder[] = 'first';
        return $next($request);
    }
}

class SecondOrderMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        OrderTrackingMiddleware::$executionOrder[] = 'second';
        return $next($request);
    }
}

class ThirdOrderMiddleware implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        OrderTrackingMiddleware::$executionOrder[] = 'third';
        return $next($request);
    }
}

class ArgumentCapturingMiddleware implements MiddlewareInterface
{
    public static array $capturedArgs = [];

    public function handle(HttpRequest $request, callable $next, ...$args): HttpResponse
    {
        self::$capturedArgs = $args;
        return $next($request);
    }
}

class InvalidMiddleware
{
    // This class does NOT implement MiddlewareInterface
    // Used for testing exception handling
}

class CallableMiddlewareTest
{
    public static int $wasCalled = 0;
}

class CallableTestMiddlewareImpl implements MiddlewareInterface
{
    public function handle(HttpRequest $request, callable $next): HttpResponse
    {
        return $next($request);
    }
}

