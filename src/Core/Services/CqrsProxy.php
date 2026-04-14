<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services;

use Zolta\Cqrs\Contracts\CqrsServiceInterface;
use Zolta\Cqrs\Services\Pipeline\ApplicationService;

/**
 * Lightweight proxy that resolves 'dot' placeholders from ApplicationService
 * captured store prior to forwarding to the real Cqrs service.
 */
final readonly class CqrsProxy implements CqrsServiceInterface
{
    public function __construct(
        private CqrsServiceInterface $cqrsService,
        private ApplicationService $applicationService
    ) {}

    /**
     * @param  class-string|object  $commandOrClass
     */
    public function dispatch($commandOrClass, mixed ...$args): mixed
    {
        [$data, $other] = $this->extractDataAndArgs($args);
        $data = $this->resolvePlaceholdersRecursively($data);
        $result = $this->cqrsService->dispatch($commandOrClass, $data, ...$other);
        $this->autoCaptureResult($commandOrClass, $result);

        return $result;
    }

    /**
     * @param  class-string|object  $queryOrClass
     */
    public function ask($queryOrClass, mixed ...$args): mixed
    {
        [$data, $other] = $this->extractDataAndArgs($args);
        $data = $this->resolvePlaceholdersRecursively($data);
        $result = $this->cqrsService->ask($queryOrClass, $data, ...$other);
        $this->autoCaptureResult($queryOrClass, $result);

        return $result;
    }

    /**
     * @param  class-string|object  $message
     */
    public function run($message, mixed ...$args): mixed
    {
        [$data, $other] = $this->extractDataAndArgs($args);
        $data = $this->resolvePlaceholdersRecursively($data);
        $result = $this->cqrsService->run($message, $data, ...$other);
        $this->autoCaptureResult($message, $result);

        return $result;
    }

    public function make(string|object $voOrClass, array $data = []): mixed
    {
        return $this->cqrsService->make($voOrClass, $data);
    }

    /**
     * @param  list<mixed>  $args
     * @return array{array<string, mixed>, list<mixed>}
     */
    private function extractDataAndArgs(array $args): array
    {
        $data = [];
        if (isset($args[0]) && is_array($args[0])) {
            $data = $args[0];
            array_shift($args);
        }

        return [$data, $args];
    }

    private function resolvePlaceholdersRecursively(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->resolvePlaceholdersRecursively($v);
            }

            return $out;
        }

        if ($value instanceof MapPlaceholder) {
            try {
                return $this->applicationService->get($value->value());
            } catch (\Throwable) {
                return $value->value();
            }
        }

        return $value;
    }

    private function autoCaptureResult(mixed $message, mixed $result): void
    {
        if ($result === null) {
            return;
        }

        $this->applicationService->captureMessage($message, $result);
    }
}
