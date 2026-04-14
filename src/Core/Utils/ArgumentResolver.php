<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Utils;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

final class ArgumentResolver
{
    /**
     * Caches:
     *  - method selection: handlerClass|commandClass|role => methodName
     *  - param metadata: handlerClass::methodName => [ ['name' => ..., 'type' => ?string, 'is_variadic'=>bool, 'has_default'=>bool, 'default'=>mixed], ...]
     *
     * static so multiple resolver instances share caches in a single process.
     */
    /** @var array<string, string> */
    private static array $methodCache = [];

    /**
     * @var array<string, list<array{name: string, type: string|null, is_variadic: bool, has_default: bool, default: mixed}>>
     */
    private static array $paramMetaCache = [];

    public function __construct(private readonly ContainerInterface $container) {}

    /**
     * Determine the entrypoint method for a handler/validator/query handler.
     *
     * Role is one of: 'handler' (command/query handler), 'validator'
     *
     * Precedence:
     * 1) __invoke() if available (callable)
     * 2) Attribute (HandlesCommand/ValidatesCommand/HandlesQuery) if attribute matches the command/query class and provides a method name
     * 3) default: 'handle' for handlers/queries, 'validate' for validators
     * 4) failure -> throw
     *
     * @param  object|string  $handler  instance or class name
     * @param  string  $targetClass  the command/query class (FQCN)
     * @param  string  $role  'handler'|'validator'|'query'
     * @return string method name to call (may be '__invoke')
     */
    public function selectMethod(object|string $handler, string $targetClass, string $role = 'handler'): string
    {
        $handlerClass = is_string($handler) ? $handler : $handler::class;
        $cacheKey = $handlerClass.'|'.$targetClass.'|'.$role;

        if (isset(self::$methodCache[$cacheKey])) {
            return self::$methodCache[$cacheKey];
        }

        // If handler is a class name, try to inspect class; if an object, reflect object
        $reflectionClass = new ReflectionClass($handlerClass);

        // 1) invokable
        if ($reflectionClass->hasMethod('__invoke')) {
            return self::$methodCache[$cacheKey] = '__invoke';
        }

        // 2) attributes: check for HandlesCommand, ValidatesCommand, HandlesQuery
        foreach ($reflectionClass->getAttributes() as $attribute) {
            $attrClass = $attribute->getName();
            // Only consider attributes we know about
            if (
                str_ends_with($attrClass, '\\HandlesCommand') ||
                str_ends_with($attrClass, '\\ValidatesCommand') ||
                str_ends_with($attrClass, '\\HandlesQuery')
            ) {
                $inst = $attribute->newInstance();

                // attribute constructors use either ->commandClass or ->queryClass
                $targetProp = $inst->commandClass ?? ($inst->queryClass ?? null);
                $methodName = $inst->methodName ?? null;

                if ($targetProp === $targetClass && $methodName) {
                    // verify method exists
                    if (! $reflectionClass->hasMethod($methodName)) {
                        throw new RuntimeException("Attribute declares method '{$methodName}' but it does not exist on {$handlerClass}");
                    }

                    return self::$methodCache[$cacheKey] = $methodName;
                }
            }
        }

        // 3) defaults depending on role
        $default = $role === 'validator' ? 'validate' : 'handle';
        if ($reflectionClass->hasMethod($default)) {
            return self::$methodCache[$cacheKey] = $default;
        }

        // 4) cannot determine
        throw new RuntimeException(sprintf(
            "Cannot determine entrypoint method for handler '%s' (role='%s'). Provide an __invoke, attribute mapping, or a '%s' method.",
            $handlerClass,
            $role,
            $default
        ));
    }

    /**
     * Resolve exactly the arguments demanded by the handler's method.
     * Returns an ordered list of arguments to pass to the method.
     *
     * Algorithm per parameter:
     * 1) if param type matches the command -> inject the command
     * 2) if one of the provided args is an instantiated object matching the type -> use it (and consume it)
     * 3) if a provided arg is a string equals to the param type (class name) -> try container->get(class) or pass the string if container doesn't have it
     * 4) container->has(paramType) -> container->get(paramType)
     * 5) if param has no type -> consume the next remaining provided arg (positional) if any
     * 6) default value if available
     * 7) cannot resolve -> throw
     *
     * Variadic params will collect all remaining provided args that match type (or any remaining if no type).
     *
     * @param  object|string  $handler  instance or class name (instance preferred)
     * @param  string  $methodName  method to inspect
     * @param  object  $command  command/query instance
     * @param  list<mixed>  $providedArgs  args passed by the caller to dispatch()
     * @return list<mixed> resolved args to pass to the method
     */
    public function resolveMethodArguments(object|string $handler, string $methodName, object $command, array $providedArgs): array
    {
        // Normalize provided args copy
        $provided = $providedArgs;
        $resolved = [];

        // get param meta
        $handlerClass = is_string($handler) ? $handler : $handler::class;
        $metaKey = $handlerClass.'::'.$methodName;

        if (! isset(self::$paramMetaCache[$metaKey])) {
            // build metadata
            $paramMetas = [];

            if (is_object($handler) && method_exists($handler, $methodName)) {
                $ref = new ReflectionMethod($handler, $methodName);
            } elseif (is_string($handler)) {
                // class name: use ReflectionMethod on class
                $ref = new ReflectionMethod($handler, $methodName);
            } else {
                // maybe callable (Closure)
                $ref = new ReflectionFunction($handler);
            }

            foreach ($ref->getParameters() as $reflectionParameter) {
                $typeObj = $reflectionParameter->getType();
                $type = null;
                if ($typeObj instanceof ReflectionNamedType) {
                    $type = $typeObj->getName();
                }
                $paramMetas[] = [
                    'name' => $reflectionParameter->getName(),
                    'type' => $type,
                    'is_variadic' => $reflectionParameter->isVariadic(),
                    'has_default' => $reflectionParameter->isDefaultValueAvailable(),
                    'default' => $reflectionParameter->isDefaultValueAvailable() ? $reflectionParameter->getDefaultValue() : null,
                ];
            }
            self::$paramMetaCache[$metaKey] = $paramMetas;
        }

        $paramMetas = self::$paramMetaCache[$metaKey];

        foreach ($paramMetas as $paramMeta) {
            $type = $paramMeta['type'];
            $isVariadic = $paramMeta['is_variadic'];

            // handle variadic: collect all remaining matches according to rules (will append each as separate param)
            if ($isVariadic) {
                // collect all remaining provided items that match the type (or all if no type)
                $collected = [];
                $left = [];

                foreach ($provided as $pv) {
                    if ($type !== null) {
                        if ($pv instanceof $type) {
                            $collected[] = $pv;

                            continue;
                        }
                        if (is_string($pv) && $pv === $type) {
                            $collected[] = $this->container->has($pv) ? $this->container->get($pv) : $pv;

                            continue;
                        }
                        // do not take non-matching items
                        $left[] = $pv;
                    } else {
                        // no type: take all remaining provided args
                        $collected[] = $pv;
                    }
                }

                // adopt collected as series of resolved args
                foreach ($collected as $c) {
                    $resolved[] = $c;
                }

                // clear provided (we consumed them)
                $provided = $left;

                continue;
            }

            // 1) command itself matched by type
            if ($type !== null && $command instanceof $type) {
                $resolved[] = $command;

                continue;
            }

            // 2) look for an instantiated object in provided
            $matchedIndex = null;
            foreach ($provided as $idx => $pv) {
                if (is_object($pv) && $type !== null && $pv instanceof $type) {
                    $matchedIndex = $idx;
                    break;
                }
            }
            if ($matchedIndex !== null) {
                $resolved[] = $provided[$matchedIndex];
                array_splice($provided, $matchedIndex, 1);

                continue;
            }

            // 3) look for provided string classname that equals the required type
            $matchedIndex = null;
            foreach ($provided as $idx => $pv) {
                if (is_string($pv) && $type !== null && $pv === $type) {
                    // try resolve from container
                    $resolved[] = $this->container->has($pv) ? $this->container->get($pv) : $pv;
                    $matchedIndex = $idx;
                    break;
                }
            }
            if ($matchedIndex !== null) {
                array_splice($provided, $matchedIndex, 1);

                continue;
            }

            // 4) container resolution by type
            if ($type !== null && $this->container->has($type)) {
                $resolved[] = $this->container->get($type);

                continue;
            }

            // 5) if param has no type, consume next positional provided arg (if any)
            if ($type === null && count($provided) > 0) {
                $resolved[] = array_shift($provided);

                continue;
            }

            // 6) default value
            if ($paramMeta['has_default']) {
                $resolved[] = $paramMeta['default'];

                continue;
            }

            // 7) cannot resolve
            throw new RuntimeException(sprintf(
                "Cannot resolve parameter '%s' (type=%s) for %s::%s",
                $paramMeta['name'],
                $type ?? 'none',
                $handlerClass,
                $methodName
            ));
        }

        return $resolved;
    }
}
