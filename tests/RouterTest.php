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
use Velo\Http\Interfaces\MiddlewareInterface;
use Velo\Router\Exceptions\PageNotFoundException;
use Velo\Router\PathResolver\PathResolver;
use Velo\Router\Pipeline\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\Pipeline\Pipeline;
use Velo\Router\Route\Route;
use Velo\Router\Router\Router;

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

    #[Test]
    public function it_registers_a_get_route(): void
    {
        $route = $this->router->get('/users', 'UserController', 'index');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('GET', $route->requestMethod);
        $this->assertSame('/users', $route->path);
        $this->assertSame('UserController', $route->controller);
        $this->assertSame('index', $route->action);

        $this->assertSame($route, $this->router->routes['GET']['/users']);
    }

    #[Test]
    public function it_registers_a_post_route(): void
    {
        $route = $this->router->post('/users', 'UserController', 'create');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('POST', $route->requestMethod);
        $this->assertSame($route, $this->router->routes['POST']['/users']);
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

        $this->assertSame(['SomeMiddleware', []], $route->getMiddleware(0));
        $this->assertSame(['AnotherMiddleware', []], $route->getMiddleware(1));
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
}

class FakeController extends Controller
{
    public static int $wasCalled = 0;
    public static array $lastArgs = [];

    public function index(HttpRequest $request): HttpResponse
    {
        self::$wasCalled++;
        return new HttpResponse(null, 200);
    }

    public function actionWithParams(HttpRequest $request, int $id, int $sth): HttpResponse
    {
        self::$wasCalled++;
        self::$lastArgs = ['id' => $id, 'sth' => $sth];
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