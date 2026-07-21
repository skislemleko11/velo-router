<?php
declare(strict_types=1);

namespace Velo\Router\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Velo\Router\PathResolver\Exceptions\PathNotFoundException;
use Velo\Router\PathResolver\PathResolver;

class PathResolverTest extends TestCase
{
    private PathResolver $pathResolver;

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
    }

    #[Test]
    public function it_has_basic_paths_registered(): void
    {
        $toCompareDirs = ['base' => 0, 'public' => 0, 'views' => 0];
        $toCompareFiles = ['error403' => 0, 'error404' => 0, 'error500' => 0];
        $this->assertTrue(
            (!array_diff_key($toCompareDirs, $this->pathResolver->dirPaths) &&
                !array_diff_key($toCompareFiles, $this->pathResolver->filePaths))
        );
    }

    #[Test]
    public function it_sets_dir_path(): void
    {
        $key = 'hehe';
        $path = '/hehe/';
        $this->pathResolver->setDirPath($key, $path);
        $this->assertTrue(
            isset($this->pathResolver->dirPaths[$key]) &&
            $this->pathResolver->dirPaths[$key] == $path
        );
    }

    #[Test]
    public function it_sets_file_path(): void
    {
        $key = 'hehe';
        $path = '/views/hehe.php';
        $this->pathResolver->setFilePath($key, $path);
        $this->assertTrue(
            isset($this->pathResolver->filePaths[$key]) &&
            $this->pathResolver->filePaths[$key] == $path
        );
    }

    #[Test]
    public function it_gets_dir_path(): void
    {
        $this->assertEquals('/public/', $this->pathResolver->getDirPath('public'));
    }

    #[Test]
    public function it_gets_file_path(): void
    {
        $this->assertEquals('/views/error404.php', $this->pathResolver->getFilePath('error404'));
    }

    #[Test]
    public function it_sets_null_file_path(): void
    {
        $key = 'error999';
        $this->pathResolver->setFilePath($key, null);
        $this->assertNull($this->pathResolver->filePaths[$key]);
    }

    #[Test]
    public function it_gets_null_file_path(): void
    {
        $this->assertNull($this->pathResolver->getFilePath('error403'));
    }

    #[Test]
    public function is_dir_registered(): void
    {
        $key = 'hehe';
        $this->pathResolver->setDirPath($key, '/views/hehe/');
        $this->assertTrue($this->pathResolver->isDirRegistered($key));
    }

    #[Test]
    public function is_file_registered(): void
    {
        $key = 'hehe';
        $this->pathResolver->setFilePath($key, '/views/hehe.php');
        $this->assertTrue($this->pathResolver->isFileRegistered($key));
    }

    public function is_file_with_null_path_registered(): void
    {
        $this->assertTrue($this->pathResolver->isFileRegistered('error403.php'));
    }

    #[Test]
    public function it_throws_path_not_found_exception_from_getFilePath(): void
    {
        $this->expectException(PathNotFoundException::class);
        $this->pathResolver->getFilePath('nonexistent');
    }

    #[Test]
    public function it_throws_path_not_found_exception_from_getDirPath(): void
    {
        $this->expectException(PathNotFoundException::class);
        $this->pathResolver->getDirPath('nonexistent');
    }
}