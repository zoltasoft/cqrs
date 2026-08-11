<?php

declare(strict_types=1);

namespace Zolta\Tests\Integration;

use Orchestra\Testbench\TestCase;
use Zolta\Cqrs\Laravel\Providers\ZoltaCqrsServiceProvider;

final class LegacyCqrsConfigurationProviderTest extends TestCase
{
    public function test_legacy_cqrs_configuration_is_merged_into_the_canonical_namespace(): void
    {
        $this->app['config']->set('zolta', [
            'queries' => [['path' => '/legacy/queries', 'namespace' => 'App\\Legacy\\']],
            'map_keys' => ['query' => 'legacy.query.map'],
        ]);
        $this->app->register(ZoltaCqrsServiceProvider::class);

        $this->assertSame([
            ['path' => '/legacy/queries', 'namespace' => 'App\\Legacy\\'],
        ], config('zolta.cqrs.queries'));
        $this->assertSame(config('zolta.cqrs.queries'), config('zolta.queries'));
        $this->assertSame('legacy.query.map', config('zolta.cqrs.map_keys.query'));
    }
}
