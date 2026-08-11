<?php

declare(strict_types=1);

namespace Zolta\Tests\Integration;

use Orchestra\Testbench\TestCase;
use Zolta\Cqrs\Laravel\Providers\ZoltaCqrsServiceProvider;

final class CanonicalCqrsConfigurationProviderTest extends TestCase
{
    public function test_canonical_cqrs_configuration_is_used_and_exposed_to_legacy_consumers(): void
    {
        $this->app['config']->set('zolta', [
            'cqrs' => [
                'commands' => [['path' => '/canonical/commands', 'namespace' => 'App\\Canonical\\']],
                'cache' => ['command' => '/tmp/canonical-command-map.php'],
            ],
        ]);
        $this->app->register(ZoltaCqrsServiceProvider::class);

        $this->assertSame([
            ['path' => '/canonical/commands', 'namespace' => 'App\\Canonical\\'],
        ], config('zolta.cqrs.commands'));
        $this->assertSame(config('zolta.cqrs.commands'), config('zolta.commands'));
        $this->assertSame('/tmp/canonical-command-map.php', config('zolta.cache.command'));
    }
}
