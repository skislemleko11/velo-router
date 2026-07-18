<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Velo\Container\Container;
use Velo\Controllers\Controller;
use Velo\Http\HttpRequest;
use Velo\Http\HttpResponse;
use Velo\Router\Exceptions\ControllerMethodInvalidReturnTypeException;
use Velo\Router\PathResolver;
use Velo\Router\Router;
use Velo\Router\Exceptions\PageNotFoundException;

class RouterTest extends TestCase
{
    protected Container $container;
    protected Router $router;
    protected PathResolver $pathResolver;

    protected function setUp(): void
    {
        $this->pathResolver = new PathResolver(
            basePath: '/',
            publicPath: '/public/',
            viewsPath: '/views/',
            error404Path: '/views/error404.php',
            error500Path: '/views/error500.php',
        );
        $this->container = new Container();
        $this->container->set(PathResolver::class, fn() => $this->pathResolver);

        $this->router = new Router($this->container);
    }

    #[Test]
    public function it_registers_a_get_route(): void
    {
        $this->router->get('/users', 'UserController', 'index');
        $this->assertSame([
            'GET' => [
                '/users' => ['UserController', 'index'],
            ]
        ], $this->router->routes);
    }

    #[Test]
    public function it_registers_a_post_route(): void
    {
        $this->router->post('/users', 'UserController', 'create');
        $this->assertSame([
            'POST' => [
                '/users' => ['UserController', 'create']
            ]
        ], $this->router->routes);
    }

    #[Test]
    public function it_resolves_a_simple_route(): void
    {
        FakeController::$wasCalled = 0;

        $this->router->get('/', FakeController::class, 'index');
        $request = new HttpRequest('/', 'GET');
        $result = $this->router->resolve($request);

        $this->assertInstanceOf(HttpResponse::class, $result);
    }

    #[Test]
    public function it_resolves_a_route_with_parameters(): void
    {
        FakeController::$wasCalled = 0;

        $this->router->get('/users/{id}/{sth}', FakeController::class, 'actionWithParams');
        $request = new HttpRequest('/users/5/hehe', 'GET');
        $result = $this->router->resolve($request);
        $this->assertInstanceOf(HttpResponse::class, $result);
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
        $this->router->get('/', FakeController::class, 'invalidReturnType');
        $request = new HttpRequest('/', 'GET');
        $this->router->resolve($request);
    }
}

class FakeController extends Controller
{
    public static int $wasCalled = 0;

    public function index(): HttpResponse
    {
        self::$wasCalled++;
        return new HttpResponse('', 1);
    }

    public function actionWithParams($a, $b): HttpResponse
    {
        self::$wasCalled++;
        return new HttpResponse('', 1);
    }

    public function invalidReturnType(): string
    {
        return 'string';
    }
}