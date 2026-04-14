<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Events\Traits;

use RuntimeException;
use Zolta\Domain\Events\Contracts\EventInterface;
use Zolta\Domain\Interfaces\VO;

/** @phpstan-ignore-next-line */
trait HandlesDomainEvents
{
    /**
     * Services that want to accept events must declare:
     * public static function eventMap(): array { return ['id' => ['getUserId','get']]; }
     */
    public function handle(EventInterface $event): void
    {
        if (! method_exists(static::class, 'eventMap')) {
            throw new RuntimeException('No eventMap defined on '.static::class);
        }

        $map = static::eventMap();
        $input = $this->mapEventToInput($event, $map);

        // If the service is flow-based we can rely on flow(); otherwise, caller must provide Flow
        if (method_exists($this, 'flow')) {
            ($this)($input, $this->flow());

            return;
        }

        // fallback: invoke without flow (service may override handle())
        ($this)($input);
    }

    protected function mapEventToInput(EventInterface $event, array $map): array
    {
        $resolved = [];
        foreach ($map as $k => $resolver) {
            if (is_callable($resolver)) {
                $value = $resolver($event);
            } elseif (is_string($resolver)) {
                $value = $this->callChainOn($event, [$resolver]);
            } elseif (is_array($resolver)) {
                $value = $this->callChainOn($event, $resolver);
            } else {
                throw new RuntimeException("Unsupported resolver for {$k}");
            }

            if ($value instanceof VO) {
                $value = $value->get();
            }

            $resolved[$k] = $value;
        }

        return $resolved;
    }

    protected function callChainOn(object $subject, array $chain)
    {
        $value = $subject;
        foreach ($chain as $step) {
            if (is_callable($step)) {
                $value = $step($value);

                continue;
            }

            if (is_object($value) && method_exists($value, $step)) {
                $value = $value->{$step}();

                continue;
            }

            if (is_object($value) && property_exists($value, $step)) {
                $value = $value->{$step};

                continue;
            }

            throw new RuntimeException("Cannot resolve step '{$step}' on ".$value::class);
        }

        return $value;
    }
}
