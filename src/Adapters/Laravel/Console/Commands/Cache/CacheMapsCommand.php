<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Console\Commands\Cache;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CacheMapsCommand extends Command
{
    protected $signature = 'zolta:maps:cache
        {--type= : Limit to a specific map type (command,query,event)}
        {--fresh : Clear existing cache files before rebuilding}';

    protected $description = 'Rebuild cached command/query/event maps using the configured scan paths.';

    public function handle(): int
    {
        $types = $this->typesFromOption();
        $fresh = (bool) $this->option('fresh');

        foreach ($types as $type) {
            $cache = $this->cacheFile($type);
            $manifest = $this->manifestFile($type);
            $mapKey = $this->mapKey($type);

            if ($fresh) {
                $this->clearFile($cache);
                $this->clearFile($manifest);
            }

            $this->laravel->forgetInstance($mapKey);

            try {
                // Resolving the map key triggers the provider to scan and rebuild.
                $map = $this->laravel->make($mapKey);
                $count = is_array($map) ? count($map) : 0;
                $this->info("✅ Rebuilt {$type} map ({$count} entries) → {$cache}");
            } catch (\Throwable $e) {
                $this->error("❌ Failed to rebuild {$type} map: {$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function typesFromOption(): array
    {
        $type = $this->option('type');
        if (! $type) {
            return ['command', 'query', 'event'];
        }

        $parts = array_filter(array_map(trim(...), explode(',', (string) $type)));
        $valid = ['command', 'query', 'event'];

        return array_values(array_intersect($valid, $parts));
    }

    private function mapKey(string $type): string
    {
        return config("zolta.map_keys.{$type}", "{$type}.map");
    }

    private function cacheFile(string $type): string
    {
        return config("zolta.cache.{$type}", base_path("bootstrap/cache/{$type}_map.php"));
    }

    private function manifestFile(string $type): string
    {
        return config("zolta.cache_manifest.{$type}", base_path("bootstrap/cache/{$type}_map_manifest.php"));
    }

    private function clearFile(string $path): void
    {
        if (File::exists($path)) {
            File::delete($path);
        }
    }
}
