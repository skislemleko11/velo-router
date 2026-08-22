<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Velo\Container\Container;
use Velo\Http\Request;
use Velo\Http\RequestMethod;
use Velo\Http\Responses\Concrete\TextResponse;
use Velo\Http\Responses\Response;
use Velo\Router\Middlewares\MiddlewareInterface;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Exceptions\MustImplementMiddlewareInterfaceException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
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
use Velo\Router\Router\Router;

final class RouterTest extends TestCase
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

    private function getRoutesProperty(object $object): mixed
    {
        $reflection = new ReflectionClass($object);
        return $reflection->getProperty('routes')
            ->getValue($object);
    }

    #[Test]
    public function it_registers_a_get_route(): void
    {
        $route = $this->router->get('/users', 'UserController', 'index');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(RequestMethod::GET, $route->requestMethod);
        $this->assertSame('/users', $route->path);
        $this->assertSame('UserController', $route->controller);
        $this->assertSame('index', $route->action);

        $this->assertSame($route, $this->getRoutesProperty($this->router)[RequestMethod::GET->value]['/users']);
    }

    #[Test]
    public function it_registers_a_post_route(): void
    {
        $route = $this->router->post('/users', 'UserController', 'create');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(RequestMethod::POST, $route->requestMethod);
        $this->assertSame($route, $this->getRoutesProperty($this->router)[RequestMethod::POST->value]['/users']);
    }

    #[Test]
    public function it_resolves_a_simple_route(): void
    {
        FakeController::$wasCalled = 0;
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/', FakeController::class, 'index');
        $request = new Request('/', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $request = new Request('/users/me', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$indexCalls);
        $this->assertSame(0, FakeController::$paramsCalls);
    }

    #[Test]
    public function it_resolves_a_route_with_parameters_and_casts_them(): void
    {
        FakeController::$wasCalled = 0;
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/users/{id}/{sth}', FakeController::class, 'actionWithParams');

        $request = new Request('/users/5/100', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $request = new Request('/flags/1/2.5', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $request = new Request('/reports/7', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(['id' => 7, 'type' => 'default'], FakeController::$lastArgs);
    }

    #[Test]
    public function it_throws_missing_required_argument_exception_when_route_is_incomplete(): void
    {
        $this->expectException(MissingRequiredArgumentException::class);
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/users/{id}', FakeController::class, 'actionWithParams');
        $request = new Request('/users/5', RequestMethod::GET);

        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_page_not_found_exception(): void
    {
        $this->expectException(RouteNotFound::class);
        $request = new Request('/users', RequestMethod::GET);
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_controller_method_invalid_return_type_exception(): void
    {
        $this->expectException(ControllerMethodInvalidReturnTypeException::class);
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/', FakeController::class, 'invalidReturnType');
        $request = new Request('/', RequestMethod::GET);
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

        $request = new Request('/dashboard', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $request = new Request('/protected', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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
            $this->assertStringContainsString('FakeController', (string)file_get_contents($cacheFile));
            $this->assertStringContainsString('FakeMiddleware', (string)file_get_contents($cacheFile));

            $cachedRouter = new Router($this->pipeline);
            $this->assertTrue($cachedRouter->loadRoutesFromCache($cacheFile));

            $request = new Request('/cached/42', RequestMethod::GET);
            $result = $cachedRouter->resolve($request);

            $this->assertInstanceOf(TextResponse::class, $result);
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

            $request = new Request('/registry', RequestMethod::GET);
            $result = $newRouter->resolve($request);

            $this->assertInstanceOf(TextResponse::class, $result);
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

                $request = new Request('/cached-test/99', RequestMethod::GET);
                $result = $newRouter->resolve($request);

                $this->assertInstanceOf(TextResponse::class, $result);
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
        $request = new Request('/test', RequestMethod::GET);
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_not_found_method_exception_when_method_does_not_exist(): void
    {
        $this->expectException(NotFoundControllerMethodException::class);

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/test', FakeController::class, 'nonExistentMethod');
        $request = new Request('/test', RequestMethod::GET);
        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_parameter_missing_type_declaration_exception(): void
    {
        $this->expectException(ParameterMissingTypeDeclarationException::class);

        $this->container->set(TypesController::class, fn() => new TypesController());

        $this->router->get('/types/{id}', TypesController::class, 'noType');
        $request = new Request('/types/5', RequestMethod::GET);
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

        $request = new Request('/ordered', RequestMethod::GET);
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

        $request = new Request('/with-args', RequestMethod::GET);
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

        $request = new Request('/bad-middleware', RequestMethod::GET);
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

        $request = new Request('/callable', RequestMethod::GET);
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

        $request = new Request('/api/v1/users/123/posts/456', RequestMethod::GET);

        try {
            $this->router->resolve($request);
            $this->fail('Expected MissingRequiredArgumentException');
        } catch (MissingRequiredArgumentException) {
            $this->assertTrue(true);
        }
    }

    #[
        Test]
    public function it_resolves_route_with_all_nullable_parameters(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/nullable/{label}/{active}/{ratio}', FakeController::class, 'actionWithNullableAndTyped');

        $request = new Request('/nullable/test/1/2.5', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
    }

    #[Test]
    public function it_handles_route_parameter_casting_for_boolean(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/bool-test/{active}/{ratio}', FakeController::class, 'actionWithNullableAndTyped');

        $request = new Request('/bool-test/0/1.5', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $exactRequest = new Request('/special/endpoint', RequestMethod::GET);
        $this->router->resolve($exactRequest);

        $this->assertSame(1, FakeController::$indexCalls);
        $this->assertSame(0, FakeController::$paramsCalls);

        FakeController::$indexCalls = 0;
        FakeController::$paramsCalls = 0;

        $paramRequest = new Request('/special/something', RequestMethod::GET);
        try {
            $this->router->resolve($paramRequest);
        } catch (MissingRequiredArgumentException) {
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

        $request = new Request('/', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
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

        $getRequest = new Request('/resource', RequestMethod::GET);
        $getResult = $this->router->resolve($getRequest);

        $this->assertInstanceOf(TextResponse::class, $getResult);
        $this->assertSame(1, FakeController::$indexCalls);
    }

    #[Test]
    public function it_throws_method_not_allowed_exception_for_existing_route_with_wrong_method(): void
    {
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/users', FakeController::class, 'index');

        $request = new Request('/users', RequestMethod::POST);

        try {
            $this->router->resolve($request);
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $exception) {
            $this->assertContains(RequestMethod::GET->value, $exception->allowedMethods);
        }
    }

    #[Test]
    public function it_throws_method_not_allowed_exception_for_parameterized_route_with_wrong_method(): void
    {
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/users/{id}', FakeController::class, 'actionWithParams');

        $request = new Request('/users/123', RequestMethod::POST);

        try {
            $this->router->resolve($request);
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $exception) {
            $this->assertContains(RequestMethod::GET->value, $exception->allowedMethods);
        }
    }

    #[Test]
    public function it_returns_all_allowed_methods_for_matching_path(): void
    {
        $this->container->set(FakeController::class, fn() => new FakeController());

        $this->router->get('/resource', FakeController::class, 'index');
        $this->router->post('/resource', FakeController::class, 'index');

        $request = new Request('/resource', RequestMethod::PUT);

        try {
            $this->router->resolve($request);
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $exception) {
            $allowedMethods = $exception->allowedMethods;

            $this->assertContains(RequestMethod::GET->value, $allowedMethods);
            $this->assertContains(RequestMethod::POST->value, $allowedMethods);
        }
    }

    #[Test]
    public function it_throws_union_type_exception_for_controller_parameter(): void
    {
        $this->expectException(ParameterUnionTypeException::class);

        $this->container->set(TypesController::class, fn() => new TypesController());

        $this->router->get('/types/{id}', TypesController::class, 'unionType');

        $request = new Request('/types/5', RequestMethod::GET);

        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_intersection_type_exception_for_controller_parameter(): void
    {
        $this->expectException(ParameterIntersectionTypeException::class);

        $this->container->set(TypesController::class, fn() => new TypesController());

        $this->router->get('/types/{value}', TypesController::class, 'intersectionType');

        $request = new Request('/types/test', RequestMethod::GET);

        $this->router->resolve($request);
    }

    #[Test]
    public function it_throws_missing_required_argument_when_parameter_is_not_nullable_and_has_no_default(): void
    {
        $this->expectException(MissingRequiredArgumentException::class);

        $this->container->set(TypesController::class, fn() => new TypesController());

        $this->router->get('/types/{id}', TypesController::class, 'requiredParameter');

        $request = new Request('/types/5', RequestMethod::GET);

        $this->router->resolve($request);
    }

    #[Test]
    public function it_passes_null_for_missing_nullable_parameter(): void
    {
        NullableController::$receivedValue = 'not-null';

        $this->container->set(
            NullableController::class,
            fn() => new NullableController()
        );

        $this->router->get('/nullable', NullableController::class, 'index');

        $request = new Request('/nullable', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertNull(NullableController::$receivedValue);
    }

    #[Test]
    public function it_does_not_pass_http_request_as_controller_argument(): void
    {
        RequestParameterController::$receivedArguments = [];

        $this->container->set(
            RequestParameterController::class,
            fn() => new RequestParameterController()
        );

        $this->router->get(
            '/request/{id}',
            RequestParameterController::class,
            'index'
        );

        $request = new Request('/request/42', RequestMethod::GET);
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame([42], RequestParameterController::$receivedArguments);
    }

    #[Test]
    public function it_casts_string_route_parameter_to_string(): void
    {
        StringParameterController::$receivedValue = null;

        $this->container->set(
            StringParameterController::class,
            fn() => new StringParameterController()
        );

        $this->router->get(
            '/string/{value}',
            StringParameterController::class,
            'index'
        );

        $request = new Request('/string/123', RequestMethod::GET);
        $this->router->resolve($request);

        $this->assertSame('123', StringParameterController::$receivedValue);
    }

    #[Test]
    public function it_loads_registry_file_and_caches_routes_when_enabled(): void
    {
        FakeController::$wasCalled = 0;

        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $registryFile = tempnam(sys_get_temp_dir(), 'velo-registry-');
        self::assertNotFalse($registryFile);

        $cacheFile = sys_get_temp_dir() . '/velo-cache-' . uniqid('', true) . '.php';

        try {
            file_put_contents(
                $registryFile,
                '<?php $router->get("/cached-registry", "' .
                FakeController::class .
                '", "index");'
            );

            $this->router->loadRoutesFromCacheIfExistsElseFromRegistryFile(
                $cacheFile,
                $registryFile,
                true
            );

            $this->assertFileExists($cacheFile);

            $newRouter = new Router($this->pipeline);

            $this->assertTrue(
                $newRouter->loadRoutesFromCache($cacheFile)
            );

            $request = new Request(
                '/cached-registry',
                RequestMethod::GET
            );

            $result = $newRouter->resolve($request);

            $this->assertInstanceOf(TextResponse::class, $result);
            $this->assertSame(1, FakeController::$wasCalled);
        } finally {
            @unlink($registryFile);
            @unlink($cacheFile);
        }
    }

    #[Test]
    public function it_does_not_overwrite_cache_when_cache_exists(): void
    {
        $cacheFile = tempnam(sys_get_temp_dir(), 'velo-cache-');
        self::assertNotFalse($cacheFile);

        $registryFile = tempnam(sys_get_temp_dir(), 'velo-registry-');
        self::assertNotFalse($registryFile);

        try {
            file_put_contents(
                $cacheFile,
                '<?php return [];'
            );

            file_put_contents(
                $registryFile,
                '<?php $router->get("/registry", "' .
                FakeController::class .
                '", "index");'
            );

            $this->router->loadRoutesFromCacheIfExistsElseFromRegistryFile(
                $cacheFile,
                $registryFile
            );

            $routes = $this->getRoutesProperty($this->router);

            $this->assertSame([], $routes);
        } finally {
            @unlink($cacheFile);
            @unlink($registryFile);
        }
    }

    #[Test]
    public function it_does_not_cache_routes_when_cache_creation_is_disabled(): void
    {
        $registryFile = tempnam(sys_get_temp_dir(), 'velo-registry-');
        self::assertNotFalse($registryFile);

        $cacheFile = sys_get_temp_dir() .
            '/velo-cache-' .
            uniqid('', true) .
            '.php';

        try {
            file_put_contents(
                $registryFile,
                '<?php $router->get("/registry", "' .
                FakeController::class .
                '", "index");'
            );

            $this->router->loadRoutesFromCacheIfExistsElseFromRegistryFile(
                $cacheFile,
                $registryFile,
                false
            );

            $this->assertFileDoesNotExist($cacheFile);

            $routes = $this->getRoutesProperty($this->router);

            $this->assertArrayHasKey(
                RequestMethod::GET->value,
                $routes
            );
        } finally {
            @unlink($registryFile);
            @unlink($cacheFile);
        }
    }

    #[Test]
    public function it_registers_every_supported_http_method(): void
    {
        $routes = [
            'get' => RequestMethod::GET,
            'post' => RequestMethod::POST,
            'put' => RequestMethod::PUT,
            'patch' => RequestMethod::PATCH,
            'delete' => RequestMethod::DELETE,
            'query' => RequestMethod::QUERY,
            'head' => RequestMethod::HEAD,
            'options' => RequestMethod::OPTIONS,
        ];

        foreach ($routes as $method => $requestMethod) {
            $path = '/method-' . $method;

            $route = $this->router->{$method}(
                $path,
                FakeController::class,
                'index'
            );

            $this->assertSame($requestMethod, $route->requestMethod);
            $this->assertSame(
                $route,
                $this->getRoutesProperty($this->router)[$requestMethod->value][$path]
            );
        }
    }

    #[Test]
    public function it_resolves_head_request_using_get_route_when_head_is_not_registered(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$indexCalls = 0;

        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $this->router->get(
            '/head-fallback',
            FakeController::class,
            'index'
        );

        $request = new Request(
            '/head-fallback',
            RequestMethod::HEAD
        );

        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(1, FakeController::$indexCalls);
    }

    #[Test]
    public function it_resolves_parameterized_head_request_using_get_route_when_head_is_not_registered(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$lastArgs = [];

        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $this->router->get(
            '/head-fallback/{id}',
            FakeController::class,
            'actionWithDefaultValue'
        );

        $request = new Request(
            '/head-fallback/42',
            RequestMethod::HEAD
        );

        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(
            ['id' => 42, 'type' => 'default'],
            FakeController::$lastArgs
        );
    }

    #[Test]
    public function it_prefers_registered_head_route_over_get_fallback(): void
    {
        FakeController::$wasCalled = 0;
        FakeController::$indexCalls = 0;
        FakeController::$headCalls = 0;

        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $this->router->get(
            '/head-priority',
            FakeController::class,
            'index'
        );

        $this->router->head(
            '/head-priority',
            FakeController::class,
            'head'
        );

        $request = new Request(
            '/head-priority',
            RequestMethod::HEAD
        );

        $result = $this->router->resolve($request);

        $this->assertInstanceOf(TextResponse::class, $result);
        $this->assertSame(1, FakeController::$wasCalled);
        $this->assertSame(0, FakeController::$indexCalls);
        $this->assertSame(1, FakeController::$headCalls);
    }

    #[Test]
    public function it_returns_method_not_allowed_for_head_when_only_non_get_route_exists(): void
    {
        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $this->router->post(
            '/head-not-allowed',
            FakeController::class,
            'index'
        );

        $this->expectException(MethodNotAllowedException::class);

        $request = new Request(
            '/head-not-allowed',
            RequestMethod::HEAD
        );

        $this->router->resolve($request);
    }

    #[Test]
    public function it_returns_all_allowed_methods_for_a_parameterized_path(): void
    {
        $this->container->set(
            FakeController::class,
            fn() => new FakeController()
        );

        $this->router->get(
            '/multi-method/{id}',
            FakeController::class,
            'actionWithDefaultValue'
        );

        $this->router->post(
            '/multi-method/{id}',
            FakeController::class,
            'actionWithDefaultValue'
        );

        $this->router->put(
            '/multi-method/{id}',
            FakeController::class,
            'actionWithDefaultValue'
        );

        try {
            $this->router->resolve(
                new Request(
                    '/multi-method/42',
                    RequestMethod::DELETE
                )
            );

            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $exception) {
            $this->assertSame(
                [
                    RequestMethod::GET->value,
                    RequestMethod::POST->value,
                    RequestMethod::PUT->value,
                ],
                $exception->allowedMethods
            );
        }
    }
}

class FakeController
{
    public static int $wasCalled = 0;
    public static int $indexCalls = 0;
    public static int $paramsCalls = 0;
    public static int $headCalls = 0;
    public static array $lastArgs = [];

    public function index(Request $request): TextResponse
    {
        self::$wasCalled++;
        self::$indexCalls++;
        return new TextResponse('hehe');
    }

    public function actionWithParams(Request $request, int $id, int $sth): TextResponse
    {
        self::$wasCalled++;
        self::$paramsCalls++;
        self::$lastArgs = ['id' => $id, 'sth' => $sth];
        return new TextResponse('hehe');
    }

    public function actionWithDefaultValue(Request $request, int $id, string $type = 'default'): TextResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['id' => $id, 'type' => $type];
        return new TextResponse('hehe');
    }

    public function actionWithNullableAndTyped(Request $request, ?string $label, bool $active, float $ratio): TextResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['label' => $label, 'active' => $active, 'ratio' => $ratio];
        return new TextResponse('hehe');
    }

    public function invalidReturnType(Request $request): string
    {
        return 'string';
    }

    public function head(Request $request): TextResponse
    {
        self::$wasCalled++;
        self::$headCalls++;

        return new TextResponse('head');
    }
}

class FakeMiddleware implements MiddlewareInterface
{
    public static int $wasCalled = 0;

    public function handle(Request $request, callable $next): Response
    {
        self::$wasCalled++;
        return $next($request);
    }
}

class StoppingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        return new TextResponse('hehe', 403);
    }
}

class TypesController
{
    public function noType(Request $request, $id): TextResponse
    {
        return new TextResponse('hehe');
    }

    public function unionType(
        Request    $request,
        int|string $id
    ): TextResponse
    {
        return new TextResponse('hehe');
    }

    public function intersectionType(
        Request                                                  $request,
        TypesControllerInterface&AnotherTypesControllerInterface $value
    ): TextResponse
    {
        return new TextResponse('hehe');
    }

    public function requiredParameter(
        Request $request,
        int     $id,
        string  $required
    ): TextResponse     
    {
        return new TextResponse('hehe');
    }
}

interface TypesControllerInterface
{
}

interface AnotherTypesControllerInterface
{
}

class OrderTrackingMiddleware implements MiddlewareInterface
{
    public static array $executionOrder = [];

    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

class FirstOrderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        OrderTrackingMiddleware::$executionOrder[] = 'first';
        return $next($request);
    }
}

class SecondOrderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        OrderTrackingMiddleware::$executionOrder[] = 'second';
        return $next($request);
    }
}

class ThirdOrderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        OrderTrackingMiddleware::$executionOrder[] = 'third';
        return $next($request);
    }
}

class ArgumentCapturingMiddleware implements MiddlewareInterface
{
    public static array $capturedArgs = [];

    public function handle(Request $request, callable $next, ...$args): Response
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
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

class NullableController
{
    public static mixed $receivedValue = null;

    public function index(
        Request $request,
        ?string $value
    ): TextResponse
    {
        self::$receivedValue = $value;

        return new TextResponse('hehe');
    }
}

class RequestParameterController
{
    public static array $receivedArguments = [];

    public function index(
        Request $request,
        int     $id
    ): TextResponse
    {
        self::$receivedArguments = [$id];

        return new TextResponse('hehe');
    }
}

class StringParameterController
{
    public static ?string $receivedValue = null;

    public function index(
        Request $request,
        string  $value
    ): TextResponse
    {
        self::$receivedValue = $value;

        return new TextResponse('hehe');
    }
}