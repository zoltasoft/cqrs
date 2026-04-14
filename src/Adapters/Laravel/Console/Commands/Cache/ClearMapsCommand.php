<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Laravel\Console\Commands\Cache;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ClearMapsCommand extends Command
{
    protected $signature = 'zolta:maps:clear
        {--type= : Limit to a specific map type (command,query,event)}';

    protected $description = 'Remove cached command/query/event maps and their manifests.';

    public function handle(): int
    {
        $types = $this->typesFromOption();
        foreach ($types as $type) {
            $cache = $this->cacheFile($type);
            $manifest = $this->manifestFile($type);

            $this->clearFile($cache);
            $this->clearFile($manifest);

            $this->info("🧹 Cleared {$type} map cache and manifest.");
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
