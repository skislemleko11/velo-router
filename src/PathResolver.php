<?php
declare(strict_types=1);

namespace Velo\Router;

use Velo\Router\Exceptions\PathNotFoundException;

class PathResolver
{
    protected(set) array $dirPaths = [];
    protected(set) array $filePaths = [];

    public function __construct(
        string $basePath,
        string $publicPath,
        string $viewsPath,
        ?string $error403Path,
        ?string $error404Path,
        ?string $error500Path,
    )
    {
        $this->dirPaths['base'] = $basePath;
        $this->dirPaths['public'] = $publicPath;
        $this->dirPaths['views'] = $viewsPath;
        $this->filePaths['error403'] = $error403Path;
        $this->filePaths['error404'] = $error404Path;
        $this->filePaths['error500'] = $error500Path;
    }

    public function setDirPath(string $key, string $path): void
    {
        $this->dirPaths[$key] = $path;
    }

    /**
     * @throws PathNotFoundException
     */
    public function getDirPath(string $key): string
    {
        if (!isset($this->dirPaths[$key]))
            throw new PathNotFoundException("The requested dir path \"$key\" not found!");

        return rtrim($this->dirPaths[$key], '/') . '/';
    }

    public function setFilePath(string $key, ?string $path): void
    {
        $this->filePaths[$key] = $path;
    }

    /**
     * @throws PathNotFoundException
     */
    public function getFilePath(string $key): ?string
    {
        if (!array_key_exists($key, $this->filePaths))
            throw new PathNotFoundException("The requested file path \"$key\" not found!");

        return $this->filePaths[$key];
    }

    public function isDirRegistered(string $path): bool
    {
        return isset($this->dirPaths[$path]);
    }

    public function isFileRegistered(string $path): bool
    {
        return array_key_exists($path, $this->filePaths);
    }
}