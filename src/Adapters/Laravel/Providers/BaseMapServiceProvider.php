<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Providers;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use ReflectionClass;

abstract class BaseMapServiceProvider extends ServiceProvider
{
    private const MANIFEST_VERSION = 1;

    /** @var array<string,int> */
    private array $files = [];

    /** @var array<string,int> */
    private array $directories = [];

    abstract protected function getConfigEntriesKey(): string;

    abstract protected function getMapType(): string;

    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @param  array<string, array<int|string, string>>  $map
     */
    abstract protected function mapClass(ReflectionClass $reflectionClass, array &$map): void;

    protected function getConfigName(): string
    {
        return 'zolta';
    }

    protected function getMapKey(): string
    {
        $mapType = $this->getMapType();

        return config($this->getConfigName().'.map_keys.'.$mapType, $mapType.'.map');
    }

    protected function getCacheFile(): string
    {
        $mapType = $this->getMapType();

        return config($this->getConfigName().'.cache.'.$mapType, storage_path("framework/{$mapType}_map.php"));
    }

    protected function getManifestFile(): string
    {
        $mapType = $this->getMapType();

        return config($this->getConfigName().'.cache_manifest.'.$mapType, base_path("bootstrap/cache/{$mapType}_map_manifest.php"));
    }

    protected function shouldUseCache(): bool
    {
        return (bool) config($this->getConfigName().'.map_cache.enabled', true);
    }

    protected function shouldForceRefresh(): bool
    {
        $envs = (array) config($this->getConfigName().'.map_cache.auto_refresh_env', ['local', 'testing']);

        return app()->environment($envs);
    }

    public function register(): void
    {
        $mapKey = $this->getMapKey();

        $this->app->singleton($mapKey, function () use ($mapKey) {
            $cacheFile = $this->getCacheFile();
            $manifestFile = $this->getManifestFile();
            $useCache = $this->shouldUseCache();
            $forceRefresh = $this->shouldForceRefresh();
            $scanEntries = $this->buildScanEntries();
            $roots = array_values(array_unique(array_filter(array_map(
                static fn (array $entry): string => $entry['path'],
                $scanEntries
            ))));

            $this->files = [];
            $this->directories = [];

            if ($useCache && ! $forceRefresh && $this->isCacheFresh($cacheFile, $manifestFile, $roots)) {
                if ($this->verboseLogging()) {
                    Log::debug('BaseMapServiceProvider: using cached map', [
                        'map_key' => $mapKey,
                        'cache' => $cacheFile,
                        'manifest' => $manifestFile,
                    ]);
                }

                return require $cacheFile;
            }

            $map = [];

            foreach ($scanEntries as $scanEntry) {
                $path = $scanEntry['path'] ?? null;
                $namespace = $scanEntry['namespace'] ?? null;

                if (! $path || ! is_dir($path)) {
                    if ($this->verboseLogging()) {
                        Log::debug('BaseMapServiceProvider: skipping path (missing or not dir)', ['path' => $path, 'namespace' => $namespace]);
                    }

                    continue;
                }

                $this->recordDirectory($path);
                $files = $this->findPhpFiles($path);

                foreach ($files as $file) {
                    // respect exclude patterns
                    if ($this->shouldExcludeFile($file)) {
                        if ($this->verboseLogging()) {
                            Log::debug('BaseMapServiceProvider: excluded file', ['file' => $file]);
                        }

                        continue;
                    }

                    // quick token check: skip files that don't declare class/interface/trait
                    if (! $this->fileContainsClassLike($file)) {
                        if ($this->verboseLogging()) {
                            Log::debug('BaseMapServiceProvider: file contains no class/interface/trait', ['file' => $file]);
                        }

                        continue;
                    }

                    $this->recordFile($file);
                    $fqcn = $this->toFqcn($file, $namespace, $path);

                    if ($fqcn === '') {
                        if ($this->verboseLogging()) {
                            Log::debug('BaseMapServiceProvider: empty FQCN derived', ['file' => $file, 'path' => $path, 'namespace' => $namespace]);
                        }

                        continue;
                    }

                    // Safely try to load/reflect - any throwable during autoload/reflection is caught
                    $this->safeDiscoverAndReflect($fqcn, $file, $map);
                }
            }

            // Cache the map
            $filesystem = new Filesystem;
            $content = '<?php return '.var_export($map, true).';';

            if ($this->shouldWriteAtomically()) {
                $tmp = $cacheFile.'.'.uniqid('tmp_', true);
                $filesystem->put($tmp, $content);
                $filesystem->move($tmp, $cacheFile);
            } else {
                $filesystem->put($cacheFile, $content);
            }

            if ($this->verboseLogging()) {
                Log::debug('BaseMapServiceProvider: map built and cached', ['map_key' => $mapKey, 'cache' => $cacheFile, 'entries_count' => count($map)]);
            }

            $this->writeManifest($manifestFile, $roots);

            return $map;
        });
    }

    /**
     * @return list<array{path:string,namespace:string|null}>
     */
    protected function buildScanEntries(): array
    {
        $configKey = $this->getConfigEntriesKey();
        $conf = config($this->getConfigName().'.'.$configKey, []);

        $entries = [];

        foreach ($conf as $item) {
            if (is_string($item)) {
                $entries[] = ['path' => $item, 'namespace' => null];
            } elseif (is_array($item)) {
                $path = $item['path'] ?? ($item[0] ?? null);
                $namespace = $item['namespace'] ?? null;
                if ($path) {
                    $entries[] = ['path' => $path, 'namespace' => $namespace];
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    protected function findPhpFiles(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $flags = \FilesystemIterator::SKIP_DOTS;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, $flags)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && strtolower((string) $fileInfo->getExtension()) === 'php') {
                $files[] = $fileInfo->getRealPath();
            }
        }

        return $files;
    }

    protected function shouldExcludeFile(string $file): bool
    {
        $excludes = config($this->getConfigName().'.options.exclude_paths', []);
        if (empty($excludes)) {
            return false;
        }

        // Convert glob-like '**/foo/**' into a PCRE and test
        foreach ($excludes as $exclude) {
            // escape slashes then replace ** with .* and * with [^/]* to approximate globbing
            $p = preg_quote((string) $exclude, '#');
            $p = str_replace('\\*\\*', '.*', $p);
            $p = str_replace('\\*', '[^/]*', $p);
            $p = "#^{$p}$#i";

            // Make file path unix style
            $fileNormalized = str_replace('\\', '/', $file);
            if (preg_match($p, $fileNormalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fast token-based check whether a file declares at least one class/interface/trait.
     * This avoids attempting to autoload route files, plain php files, migrations whose filename differs from the class, etc.
     */
    protected function fileContainsClassLike(string $file): bool
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $tokens = token_get_all($content);
        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Safely try to autoload and reflect the class. Catch throwable to avoid fataling the whole process.
     */
    /**
     * @param  array<string, mixed>  $map
     */
    protected function safeDiscoverAndReflect(string $fqcn, string $file, array &$map): void
    {
        try {
            // allow autoload to run; both classes, interfaces, traits may be relevant for attributes
            $exists = false;
            // Note: we intentionally use true to allow autoloader, but we protect with try/catch
            if (class_exists($fqcn, true) || interface_exists($fqcn, true) || trait_exists($fqcn, true)) {
                $exists = true;
            }

            if (! $exists) {
                if ($this->verboseLogging()) {
                    Log::debug('BaseMapServiceProvider: class not found', ['fqcn' => $fqcn, 'file' => $file]);
                }

                return;
            }

            $reflectionClass = new ReflectionClass($fqcn);
            $this->mapClass($reflectionClass, $map);
        } catch (\Throwable $e) {
            // Log then continue - don't let a single broken class break the whole scan
            Log::error('BaseMapServiceProvider: throwable while loading/reflection', [
                'fqcn' => $fqcn,
                'file' => $file,
                'message' => $e->getMessage(),
                'type' => $e::class,
            ]);

            return;
        }
    }

    protected function toFqcn(string $file, ?string $namespace, string $basePath): string
    {
        $filePath = str_replace('\\', '/', $file);
        $basePathNormalized = str_replace('\\', '/', rtrim($basePath, '/'));

        if (str_starts_with($filePath, $basePathNormalized)) {
            $relative = substr($filePath, strlen($basePathNormalized));
        } else {
            $relative = '/'.ltrim(str_replace($basePathNormalized, '', $filePath), '/');
        }

        $relative = ltrim((string) preg_replace('#\.php$#i', '', $relative), '/');
        $classPath = str_replace('/', '\\', $relative);

        if ($namespace) {
            $prefix = rtrim($namespace, '\\').'\\';

            return $prefix.$classPath;
        }

        return $classPath;
    }

    protected function shouldWriteAtomically(): bool
    {
        return (bool) config($this->getConfigName().'.options.write_atomic', true);
    }

    protected function verboseLogging(): bool
    {
        return (bool) config($this->getConfigName().'.options.verbose_logging', false);
    }

    /**
     * @param  list<string>  $expectedRoots
     */
    private function isCacheFresh(string $cacheFile, string $manifestFile, array $expectedRoots): bool
    {
        if (! is_file($cacheFile) || ! is_file($manifestFile)) {
            return false;
        }

        $manifest = @include $manifestFile;
        if (! is_array($manifest)) {
            return false;
        }

        if (($manifest['version'] ?? null) !== self::MANIFEST_VERSION) {
            return false;
        }

        if (($manifest['map_type'] ?? null) !== $this->getMapType()) {
            return false;
        }

        $manifestRoots = (array) ($manifest['roots'] ?? []);
        if ($manifestRoots === []) {
            return false;
        }

        if (! $this->rootsMatch($expectedRoots, $manifestRoots)) {
            return false;
        }

        $directories = (array) ($manifest['directories'] ?? []);
        foreach ($directories as $relative => $mtime) {
            $absolute = $this->toAbsolutePath($relative);
            if (! is_dir($absolute) || $this->safeFileMTime($absolute) !== (int) $mtime) {
                return false;
            }
        }

        $files = (array) ($manifest['files'] ?? []);
        foreach ($files as $relative => $mtime) {
            $absolute = $this->toAbsolutePath($relative);
            if (! is_file($absolute) || $this->safeFileMTime($absolute) !== (int) $mtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $manifestRoots
     */
    private function rootsMatch(array $expected, array $manifestRoots): bool
    {
        $normalize = static function (array $paths): array {
            $normalized = [];
            foreach ($paths as $path) {
                $real = realpath($path);
                $normalized[] = $real !== false ? $real : rtrim($path, DIRECTORY_SEPARATOR);
            }
            sort($normalized);

            return $normalized;
        };

        return $normalize($expected) === $normalize($manifestRoots);
    }

    /**
     * @param  list<string>  $roots
     */
    private function writeManifest(string $manifestFile, array $roots): void
    {
        ksort($this->files);
        ksort($this->directories);

        $payload = [
            'version' => self::MANIFEST_VERSION,
            'map_type' => $this->getMapType(),
            'generated_at' => time(),
            'roots' => array_values($roots),
            'files' => $this->files,
            'directories' => $this->directories,
        ];

        $contents = "<?php\n\nreturn ".var_export($payload, true).";\n";
        $filesystem = new Filesystem;
        if ($this->shouldWriteAtomically()) {
            $tmp = $manifestFile.'.'.uniqid('tmp_', true);
            $filesystem->put($tmp, $contents);
            $filesystem->move($tmp, $manifestFile);
        } else {
            $filesystem->put($manifestFile, $contents);
        }
    }

    private function recordFile(string $absolutePath): void
    {
        $relative = $this->toRelativePath($absolutePath);
        $this->files[$relative] = $this->safeFileMTime($absolutePath);
        $this->recordDirectory(dirname($absolutePath));
    }

    private function recordDirectory(string $absolutePath): void
    {
        $absolutePath = rtrim($absolutePath, DIRECTORY_SEPARATOR);
        $relative = $this->toRelativePath($absolutePath);

        if (! isset($this->directories[$relative])) {
            $this->directories[$relative] = $this->safeFileMTime($absolutePath);
        }
    }

    private function toRelativePath(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);
        $base = str_replace('\\', '/', base_path());

        if (Str::startsWith($absolute, $base.'/')) {
            return Str::after($absolute, $base.'/');
        }

        return $absolute;
    }

    private function toAbsolutePath(string $relative): string
    {
        if (Str::startsWith($relative, '/')) {
            return $relative;
        }

        return base_path($relative);
    }

    private function safeFileMTime(string $path): int
    {
        $mtime = @filemtime($path);

        return $mtime === false ? 0 : (int) $mtime;
    }
}
