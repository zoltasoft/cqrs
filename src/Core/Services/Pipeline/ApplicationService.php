<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Services\Pipeline;

use InvalidArgumentException;
use ReflectionClass;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Contracts\CqrsServiceInterface;
use Zolta\Cqrs\Contracts\MessagePayloadInterface;
use Zolta\Cqrs\Contracts\TransactionManagerInterface;
use Zolta\Cqrs\Events\Contracts\EventDispatcherInterface;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Cqrs\Services\CqrsProxy;
use Zolta\Cqrs\Services\Option;
use Zolta\Cqrs\Services\Result;
use Zolta\Domain\Events\Contracts\EventInterface;

use function array_merge;

class ApplicationService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $captured = [];

    private ?CqrsProxy $cqrsProxy = null;

    public function __construct(
        private readonly CqrsServiceInterface $cqrsService,
        private readonly ?TransactionManagerInterface $transactionManager = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function cqrs(): CqrsServiceInterface
    {
        if (! $this->cqrsProxy instanceof CqrsProxy) {
            $this->cqrsProxy = new CqrsProxy($this->cqrsService, $this);
        }

        return $this->cqrsProxy;
    }

    public function transactional(callable $callback): mixed
    {
        if (! $this->transactionManager instanceof TransactionManagerInterface) {
            // No transaction manager provided → fallback to direct execution
            return $callback($this);
        }

        $this->transactionManager->begin();

        try {
            $result = $callback($this);

            // --- Auto-rollback detection ---
            if (
                ($result instanceof Result && $result->isFailure()) ||
                ($result instanceof Option && $result->isNone())
            ) {
                $this->transactionManager->rollback();

                return $result;
            }

            // --- Otherwise commit ---
            $this->transactionManager->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->transactionManager->rollback();
            throw $e;
        }
    }

    public function runAndCapture(CommandInterface|QueryInterface|string $message, mixed ...$args): Result|Option
    {
        try {
            $result = $this->cqrs()->run($message, ...$args);
        } catch (\Throwable $e) {
            $this->capture(
                ['error' => $e],
                $this->resolveMessageAlias($message) . ':error'
            );

            if ($this->isCommandMessage($message)) {
                return Result::failure($e);
            }

            if ($this->isQueryMessage($message)) {
                return Option::error($e);
            }

            throw $e;
        }

        $this->captureMessage($message, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function capture(array $data, ?string $alias = null): void
    {
        $alias ??= '__manual__.' . count($this->captured);
        $this->captured[$alias] = $data;
    }

    public function captureMessage(CommandInterface|QueryInterface|string $message, mixed $envelope): void
    {
        $alias = $this->resolveMessageAlias($message);
        $payload = $this->extractPayload($envelope);

        if ($payload === null) {
            unset($this->captured[$alias]);

            return;
        }

        $this->captured[$alias] = $payload;
    }

    /**
     * Dispatch a batch of domain events.
     *
     * @param  EventInterface[]  $events
     */
    public function dispatchEvents(array $events): void
    {
        if (! $this->eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }

        foreach ($events as $event) {
            if ($event instanceof EventInterface) {
                $this->eventDispatcher->dispatch($event);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getCaptured(): array
    {
        return $this->captured;
    }

    public function clearCaptured(): void
    {
        $this->captured = [];
    }

    /**
     * @param  array<string, mixed>|string  $map
     */
    public function get(array|string $map): mixed
    {
        return $this->buildArray($map, null);
    }

    /**
     * Build a mapped array from a map definition and a context.
     *
     * $map can be an array mapping or a dot-path string.
     * $context if null will be the merged captured store.
     *
     * @param  mixed|null  $rootContext  internal use to keep the full merged context available
     */
    /**
     * @param  array<string, mixed>|string  $map
     * @param  array<string, mixed>|object|null  $context
     * @param  array<string, mixed>|null  $rootContext
     */
    public function buildArray(array|string $map, mixed $context = null, mixed $rootContext = null): mixed
    {
        // --- Build initial context ---
        if ($context === null) {
            $context = [];
            foreach ($this->captured as $alias => $payload) {
                if (! is_array($payload)) {
                    continue;
                }
                $context = array_merge($context, $payload);
                $context[$alias] = $payload;
                $context['@' . $alias] = $payload;
            }
            $rootContext = $context;
        } elseif ($rootContext === null) {
            $rootContext = is_array($context) ? $context : null;
        }

        // --- Handle string path ---
        if (is_string($map)) {
            $map = $this->normalizePathForContext($map, $context);
            $segments = explode('.', $map);
            $value = $context;

            foreach ($segments as $idx => $segment) {
                // Clean markers
                $segment = trim($segment, '[]');

                if (is_array($value)) {
                    if (array_key_exists($segment, $value)) {
                        $value = $value[$segment];

                        continue;
                    }

                    if ($idx === 0 && is_array($rootContext) && array_key_exists($segment, $rootContext)) {
                        $value = $rootContext[$segment];

                        continue;
                    }

                    $value = null;
                    break;
                }

                if (is_object($value)) {
                    $getter = 'get' . ucfirst($segment);
                    if (method_exists($value, $getter)) {
                        $value = $value->{$getter}();

                        continue;
                    }

                    if (method_exists($value, $segment)) {
                        $value = $value->{$segment}();

                        continue;
                    }

                    if (property_exists($value, $segment)) {
                        $value = $value->{$segment};

                        continue;
                    }

                    if (method_exists($value, '__get')) {
                        $value = $value->{$segment};

                        continue;
                    }

                    if (method_exists($value, 'get')) {
                        try {
                            $value = $value->get($segment);

                            continue;
                        } catch (\Throwable) {
                        }
                    }

                    // fallback to root
                    if ($idx === 0 && is_array($rootContext) && array_key_exists($segment, $rootContext)) {
                        $value = $rootContext[$segment];

                        continue;
                    }

                    $value = null;
                    break;
                }

                $value = null;
                break;
            }

            return $value;
        }

        // --- Handle nested map ---
        $result = [];

        foreach ($map as $key => $path) {
            // 1️⃣ Detect explicit collection
            $isCollection = false;
            $keyName = $key;

            if (is_string($key) && str_starts_with($key, '[') && str_ends_with($key, ']')) {
                $isCollection = true;
                $keyName = trim($key, '[]');
            }

            if ($isCollection && is_array($path)) {
                $contextClass = is_object($context)
                    ? strtolower((new ReflectionClass($context))->getShortName())
                    : 'user';

                // Resolve the collection from the current context (role.permissions OR user.permissions)
                $collectionContext = $this->buildArray("$contextClass.$keyName", $context, $rootContext);

                if (is_iterable($collectionContext)) {
                    $result[$keyName] = [];

                    // 🔧 Pre-normalize the child map ONCE:
                    $normalizedChildMap = $this->stripPrefixFromMap($path, $contextClass);
                    $normalizedChildMap = $this->stripPrefixFromMap($normalizedChildMap, $contextClass . 's');

                    foreach ($collectionContext as $item) {
                        // Now each scalar like "role.permissions.id.value" becomes "id.value"
                        $result[$keyName][] = $this->buildArray($normalizedChildMap, $item, $rootContext);
                    }

                    continue;
                }

                $result[$keyName] = [];

                continue;
            }

            // 2️⃣ Handle nested object map
            if (is_array($path)) {
                $subContext = null;
                if (is_array($context) && array_key_exists($key, $context)) {
                    $subContext = $context[$key];
                    $normalized = $this->stripPrefixFromMap($path, $key);
                    $result[$key] = $this->buildArray($normalized, $subContext, $rootContext);

                    continue;
                }

                $commonPrefix = $this->detectCommonPrefix($path);
                if ($commonPrefix !== null && is_array($rootContext) && array_key_exists($commonPrefix, $rootContext)) {
                    $subContext = $rootContext[$commonPrefix];
                    $normalized = $this->stripPrefixFromMap($path, $commonPrefix);
                    $result[$key] = $this->buildArray($normalized, $subContext, $rootContext);

                    continue;
                }

                $result[$key] = $this->buildArray($path, $context, $rootContext);

                continue;
            }

            // 3️⃣ Scalar path
            $result[$key] = $this->buildArray((string) $path, $context, $rootContext);
        }

        return $result;
    }

    /**
     * If the child paths share the same top-level prefix, return it; otherwise null.
     */
    /**
     * @param  array<string, mixed>  $map
     */
    private function detectCommonPrefix(array $map): ?string
    {
        $prefixes = [];
        foreach ($map as $v) {
            if (! is_string($v)) {
                continue;
            }
            $first = explode('.', $v, 2)[0];
            if ($first !== '') {
                $prefixes[$first] = true;
            }
        }
        if (count($prefixes) === 1) {
            return array_key_first($prefixes);
        }

        return null;
    }

    /**
     * Strip a top-level prefix from all string paths in the map recursively.
     */
    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function stripPrefixFromMap(array $map, string $prefix): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($v)) {
                $out[$k] = str_starts_with($v, $prefix . '.') ? substr($v, strlen($prefix) + 1) : $v;
            } elseif (is_array($v)) {
                $out[$k] = $this->stripPrefixFromMap($v, $prefix);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private function normalizePathForContext(string $path, mixed $context): string
    {
        if (! is_object($context)) {
            return $path;
        }

        $class = strtolower((new ReflectionClass($context))->getShortName()); // e.g. "permission"
        $segments = explode('.', $path);

        if (count($segments) > 0) {
            $first = strtolower($segments[0]);
            $second = isset($segments[1]) ? strtolower($segments[1]) : '';

            // Case 1: self prefix, e.g. "role.id" in Role context
            if ($first === $class) {
                return implode('.', array_slice($segments, 1));
            }

            // Case 1b: first is plural self, e.g. "permissions.id" in Permission context
            if ($first === $class . 's') {
                return implode('.', array_slice($segments, 1));
            }

            // Case 2: parent + plural child, e.g. "role.permissions.x" in Permission context
            // Strip the first TWO segments so we land at child-relative: "x"
            if ($second === $class . 's') {
                return implode('.', array_slice($segments, 2));
            }

            // (Keep your existing special-case for user.* if you want, but the line above generalizes it)
        }

        return $path;
    }

    /**
     * Optional helper to instantiate a DTO from a map using the same buildArray rules.
     */
    /**
     * Build a response from the captured data.
     *
     * - If $responseDto is provided (string): instantiates a DTO using constructor mapping.
     * - If $responseDto is null: returns the associative array resolved from the map.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|object
     */
    public function response(array $map, ?string $responseDto = null): object|array
    {
        // If no DTO class provided → directly resolve and return associative array
        if ($responseDto === null) {
            return $this->buildArray($map, null);
        }

        if (! class_exists($responseDto)) {
            throw new InvalidArgumentException("DTO class {$responseDto} does not exist.");
        }

        $mappedData = $this->buildArray($map, null);

        $reflectionClass = new ReflectionClass($responseDto);
        $ctor = $reflectionClass->getConstructor();

        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            return new $responseDto;
        }

        $args = [];
        foreach ($ctor->getParameters() as $reflectionParameter) {
            $name = $reflectionParameter->getName();
            $args[] = $mappedData[$name] ?? ($reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null);
        }

        return $reflectionClass->newInstanceArgs($args);
    }

    private function isCommandMessage(CommandInterface|QueryInterface|string $message): bool
    {
        if ($message instanceof CommandInterface) {
            return true;
        }

        return is_string($message)
            && class_exists($message)
            && is_subclass_of($message, CommandInterface::class);
    }

    private function isQueryMessage(CommandInterface|QueryInterface|string $message): bool
    {
        if ($message instanceof QueryInterface) {
            return true;
        }

        return is_string($message)
            && class_exists($message)
            && is_subclass_of($message, QueryInterface::class);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPayload(mixed $envelope): ?array
    {
        if ($envelope instanceof Result) {
            if ($envelope->isFailure()) {
                return null;
            }

            return $envelope->toArray();
        }

        if ($envelope instanceof Option) {
            if ($envelope->isNone()) {
                return null;
            }

            return $envelope->getOrNull();
        }

        if ($envelope instanceof MessagePayloadInterface) {
            return $envelope->toArray();
        }

        if (is_array($envelope)) {
            return $envelope;
        }

        if ($envelope === null) {
            return null;
        }

        return ['result' => $envelope];
    }

    private function resolveMessageAlias(CommandInterface|QueryInterface|string $message): string
    {
        if ($message instanceof CommandInterface || $message instanceof QueryInterface) {
            return $message::class;
        }

        if ($message === '') {
            return '';
        }

        return ltrim((string) $message, '\\');
    }
}
