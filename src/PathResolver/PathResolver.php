<?php
declare(strict_types=1);

namespace Velo\Router\PathResolver;

use Velo\Router\PathResolver\Exceptions\PathNotFoundException;

/**
 * Sets and resolves given paths.
 *
 * File paths can be null, but directory paths cannot.
 * It's because null file path will result in JSON or another kind of API response,
 * whereas null directory path would result in errors when resolving files.
 */
class PathResolver
{
    private array $dirPaths = [];
    private array $filePaths = [];

    public function __construct(
        string  $basePath,
        string  $publicPath,
        string  $viewsPath,
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

    /**
     * Sets the given directory path to the given key.
     *
     * @param string $path Cannot be null.
     */
    public function setDirPath(string $key, string $path): void
    {
        $this->dirPaths[$key] = $path;
    }

    /**
     * Gets the directory path for the given key.
     *
     * @throws PathNotFoundException
     * @return string It cannot return null, because directory path cannot be null.
     */
    public function getDirPath(string $key): string
    {
        if (!isset($this->dirPaths[$key])) {
            throw new PathNotFoundException("The requested dir path \"$key\" not found!");
        }

        return rtrim($this->dirPaths[$key], '/') . '/';
    }

    /**
     * Sets the given file path to the given key.
     *
     * @param string|null $path Can be null.
     */
    public function setFilePath(string $key, ?string $path): void
    {
        $this->filePaths[$key] = $path;
    }

    /**
     * Gets the file path for the given key.
     *
     * @throws PathNotFoundException
     *
     * @return string|null It can return null because file path can be null.
     */
    public function getFilePath(string $key): ?string
    {
        if (!array_key_exists($key, $this->filePaths)) {
            throw new PathNotFoundException("The requested file path \"$key\" not found!");
        }

        return $this->filePaths[$key];
    }

    /**
     * Returns if the given directory path is set.
     *
     * Uses isset instead of array_key_exists,
     * because directory path can't be set to null and isset is a bit faster(it doesn't matter much tho)
     * and just shorter to write.
     */
    public function isDirRegistered(string $path): bool
    {
        return isset($this->dirPaths[$path]);
    }

    /**
     * Returns if the given file path is set.
     *
     * Uses array_key_exists because file path can be set to null,
     * so isset would result in a mistake if the file path was null.
     */
    public function isFileRegistered(string $path): bool
    {
        return array_key_exists($path, $this->filePaths);
    }
}