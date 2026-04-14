<?php

declare(strict_types=1);

namespace Zolta\Cqrs\Hydration;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Zolta\Cqrs\Commands\Contracts\CommandInterface;
use Zolta\Cqrs\Queries\Contracts\QueryInterface;
use Zolta\Domain\Cache\ReflectionCache;
use Zolta\Domain\Interfaces\VO;
use Zolta\Domain\ValueObjects\ValueObject;
use Zolta\Domain\ValueObjects\VOConstructionContext;

final class DefaultMessageHydrator implements MessageHydratorInterface
{
    /**
     * Hydrate any ValueObject, Command, Query, or generic class.
     *
     *
     * @param  class-string|object  $target
     * @param  array<string,mixed>  $data
     */
    public function hydrate(string|object $target, array $data = []): object
    {
        // already an instance
        if (is_object($target)) {
            return $target;
        }

        if (! class_exists($target)) {
            throw new InvalidArgumentException("Class {$target} not found");
        }

        // warm cache for class attributes (optional)
        ReflectionCache::getClassAttributes($target);

        $reflectionClass = new ReflectionClass($target);

        // Command / Query: build from schema() OR fallback to constructor mapping when schema absent
        if (
            $reflectionClass->implementsInterface(CommandInterface::class)
            || $reflectionClass->implementsInterface(QueryInterface::class)
        ) {
            if (method_exists($target, 'schema')) {
                return $this->buildCommandFromSchema($target, $data);
            }

            // no schema provided: try to map data directly to constructor
            return $this->buildObjectFromConstructor($target, $data);
        }

        // ValueObject subclass -> buildVo
        if (is_subclass_of($target, VO::class)) {
            return $this->buildVo($data, $target);
        }

        // Generic class fallback: instantiate by mapping $data to ctor params
        $ctor = $reflectionClass->getConstructor();
        if ($ctor === null) {
            return new $target;
        }

        // If associative array: map by name; else positional
        $ctorArgs = [];
        if ($this->isAssoc($data)) {
            $ctorArgs = $this->buildArgsForClass($reflectionClass, $data);
        } else {
            $params = $ctor->getParameters();
            foreach ($params as $i => $p) {
                if (array_key_exists($i, $data)) {
                    $ctorArgs[] = $data[$i];
                } elseif ($p->isDefaultValueAvailable()) {
                    $ctorArgs[] = $p->getDefaultValue();
                } else {
                    $ctorArgs[] = null;
                }
            }
        }

        return $reflectionClass->newInstanceArgs($ctorArgs);
    }

    // ---------------------------------------------------------------------
    // 1) buildCommandFromSchema + helpers (from old VOBuilderTrait)
    // ---------------------------------------------------------------------

    /**
     * Build a command instance by calling its static schema(...) and using the
     * resulting nested schema to construct VOs and other constructor arguments.
     *
     * @param  class-string  $commandClass
     */
    private function buildCommandFromSchema(string $commandClass, mixed $input = []): object
    {
        if (! class_exists($commandClass)) {
            throw new InvalidArgumentException("Command class {$commandClass} not found");
        }

        if (! method_exists($commandClass, 'schema')) {
            throw new InvalidArgumentException("Command {$commandClass} must provide static schema(...) method");
        }

        $reflectionMethod = new ReflectionMethod($commandClass, 'schema');

        // Build args to call schema(...).
        $schemaArgs = [];
        $params = $reflectionMethod->getParameters();

        // Map input to schema parameters (positional/associative/object aware)
        if (count($params) === 0) {
            $schemaArgs = [];
        } elseif (count($params) === 1) {
            // common case: pass whole input as-is (we will normalize each param according to its expected type)
            $rawCandidate = $input;
            // normalize for the first (and only) schema param
            $schemaArgs[] = $this->normalizeForSchemaParam($params[0], $rawCandidate);
        } elseif (is_array($input) && $this->isAssoc($input)) {
            // multiple params - try to map intelligently
            foreach ($params as $param) {
                $pname = $param->getName();
                $candidate = array_key_exists($pname, $input)
                    ? $input[$pname]
                    : ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);

                $schemaArgs[] = $this->normalizeForSchemaParam($param, $candidate);
            }
        } elseif (is_object($input)) {
            foreach ($params as $param) {
                $pname = $param->getName();
                $val = $this->valueFromObject($input, $pname);
                if ($val === null && $param->isDefaultValueAvailable()) {
                    $val = $param->getDefaultValue();
                }
                $schemaArgs[] = $this->normalizeForSchemaParam($param, $val);
            }
        } elseif (is_array($input)) { // numeric positional
            $pos = 0;
            foreach ($params as $param) {
                $candidate = array_key_exists($pos, $input)
                    ? $input[$pos]
                    : ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);

                $schemaArgs[] = $this->normalizeForSchemaParam($param, $candidate);
                $pos++;
            }
        } else {
            // scalar: give to first param, defaults to others
            $first = true;
            foreach ($params as $param) {
                if ($first) {
                    $schemaArgs[] = $this->normalizeForSchemaParam($param, $input);
                    $first = false;

                    continue;
                }
                $schemaArgs[] = $param->isDefaultValueAvailable()
                    ? $param->getDefaultValue()
                    : null;
            }
        }

        // Invoke schema statically
        $schema = $reflectionMethod->invokeArgs(null, $schemaArgs);

        if (! is_array($schema)) {
            throw new InvalidArgumentException("schema() of {$commandClass} must return an array");
        }

        // Map schema to constructor args for the command itself
        $cmdRef = new ReflectionClass($commandClass);
        $ctor = $cmdRef->getConstructor();
        $ctorParams = $ctor ? $ctor->getParameters() : [];

        $ctorArgs = [];

        foreach ($ctorParams as $ctorParam) {
            $pname = $ctorParam->getName();

            // Value for this constructor parameter in schema (by name)
            $raw = null;
            if (array_key_exists($pname, $schema)) {
                $raw = $schema[$pname];
            } else {
                // case-insensitive match fallback
                foreach ($schema as $k => $v) {
                    if (strcasecmp((string) $k, $pname) === 0) {
                        $raw = $v;
                        break;
                    }
                }
            }

            if ($raw === null) {
                if ($ctorParam->isDefaultValueAvailable()) {
                    $ctorArgs[] = $ctorParam->getDefaultValue();

                    continue;
                }
                throw new InvalidArgumentException("Missing value for constructor parameter '{$pname}' of {$commandClass}");
            }

            $type = $ctorParam->getType();
            $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : null;

            // Handle ValueObject subclass
            if ($typeName !== null && class_exists($typeName) && is_subclass_of($typeName, ValueObject::class)) {
                if ($raw instanceof $typeName) {
                    $ctorArgs[] = $raw;
                } elseif (is_array($raw)) {
                    $ctorArgs[] = $this->buildVo($raw, $typeName);
                } else {
                    $ctorArgs[] = $this->buildNestedVo($raw, $typeName);
                }

                continue;
            }

            // Handle enum parameter
            if ($typeName !== null && enum_exists($typeName)) {
                $ctorArgs[] = $this->buildEnumValue($typeName, $raw);

                continue;
            }

            // Handle other classes (non-VO)
            if ($typeName !== null && class_exists($typeName) && ! is_subclass_of($typeName, ValueObject::class)) {
                if ($raw instanceof $typeName) {
                    $ctorArgs[] = $raw;
                } elseif (is_array($raw)) {
                    $subRef = new ReflectionClass($typeName);
                    $ctorArgs[] = $subRef->newInstanceArgs($this->buildArgsForClass($subRef, $raw));
                } else {
                    // scalar: attempt to new $typeName($raw) if possible
                    try {
                        $ctorArgs[] = new $typeName($raw);
                    } catch (\Throwable) {
                        // fallback: raw as-is
                        $ctorArgs[] = $raw;
                    }
                }

                continue;
            }

            // primitive or other: use raw as-is
            $ctorArgs[] = $raw;
        }

        // instantiate the command
        return $cmdRef->newInstanceArgs($ctorArgs);
    }

    /**
     * Normalize candidate for a schema parameter according to its declared type.
     * Tries to unwrap VO instances/arrays into primitives when schema param expects builtin.
     */
    private function normalizeForSchemaParam(ReflectionParameter $reflectionParameter, mixed $candidate): mixed
    {
        // If param expects nothing (no type), pass as-is.
        $type = $reflectionParameter->getType();
        if ($type === null) {
            return $candidate;
        }

        if ($type instanceof ReflectionNamedType) {
            $typeName = $type->getName();

            // Enum param -> don't eagerly convert here; return candidate (builder will handle)
            if (enum_exists($typeName)) {
                return $candidate;
            }

            // If builtin (string/int/bool/float), try to unwrap
            if ($type->isBuiltin()) {
                // If candidate is object and is VO: try to extract scalar
                if (is_object($candidate)) {
                    // VO instance?
                    if (
                        $candidate instanceof VO
                        || method_exists($candidate, 'get')
                    ) {
                        try {
                            $g = $candidate->get();
                            // if returns array with 'value' or 'id', prefer those
                            if (is_array($g)) {
                                foreach (['value', 'id', 'name', 'email'] as $k) {
                                    if (array_key_exists($k, $g)) {
                                        return $g[$k];
                                    }
                                }
                                // if single scalar in array, return it
                                if (count($g) === 1) {
                                    $only = array_values($g)[0];
                                    if (is_scalar($only)) {
                                        return $only;
                                    }
                                }
                            } elseif (is_scalar($g)) {
                                return $this->castScalarToBuiltin($g, $typeName);
                            }
                        } catch (\Throwable) {
                            // ignore and fallthrough
                        }
                    }

                    // if object has __toString and param expects string
                    if ($typeName === 'string' && method_exists($candidate, '__toString')) {
                        return (string) $candidate;
                    }

                    return $candidate;
                }

                // If array: common pattern ['value'=>...]
                if (is_array($candidate)) {
                    if (array_key_exists('value', $candidate)) {
                        return $this->castScalarToBuiltin($candidate['value'], $typeName);
                    }
                    // common keys fallback
                    foreach (['id', 'name', 'email'] as $k) {
                        if (array_key_exists($k, $candidate)) {
                            return $this->castScalarToBuiltin($candidate[$k], $typeName);
                        }
                    }
                    // if numeric shorthand [0=>value]
                    $firstNum = $this->firstNumericKey($candidate);
                    if ($firstNum !== null) {
                        return $this->castScalarToBuiltin($candidate[$firstNum], $typeName);
                    }

                    // otherwise can't normalize
                    return $candidate;
                }

                // scalar: cast to expected builtin
                if (is_scalar($candidate) || $candidate === null) {
                    return $this->castScalarToBuiltin($candidate, $typeName);
                }
            } else {
                // Non-builtin type: if candidate is VO or array, pass as-is; constructor will handle.
                return $candidate;
            }
        }

        return $candidate;
    }

    /**
     * Cast scalar to builtin target type (string|int|float|bool) safely.
     */
    private function castScalarToBuiltin(mixed $value, string $typeName): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($typeName) {
            'string' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            default => $value,
        };
    }

    /**
     * Try to return a candidate value from an object (DTO) for the requested param name.
     */
    private function valueFromObject(object $obj, string $paramName): mixed
    {
        if (property_exists($obj, $paramName)) {
            return $obj->{$paramName};
        }

        $getter = 'get'.ucfirst($paramName);
        if (method_exists($obj, $getter)) {
            return $obj->{$getter}();
        }
        $is = 'is'.ucfirst($paramName);
        if (method_exists($obj, $is)) {
            return $obj->{$is}();
        }

        if (method_exists($obj, 'toArray')) {
            $arr = $obj->toArray();
            if (array_key_exists($paramName, $arr)) {
                return $arr[$paramName];
            }
        }

        // aliases
        $aliases = [$paramName, lcfirst($paramName), strtolower($paramName)];
        foreach ($aliases as $alias) {
            if (property_exists($obj, $alias)) {
                return $obj->{$alias};
            }
            if (method_exists($obj, 'get'.ucfirst($alias))) {
                return $obj->{'get'.ucfirst($alias)}();
            }
        }

        return null;
    }

    /**
     * Build args for a general class reflection using a keyed array.
     */
    /**
     * @param  array<string, mixed>  $data
     * @param  ReflectionClass<object>  $reflectionClass
     * @return array<int, mixed>
     */
    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>
     */
    private function buildArgsForClass(ReflectionClass $reflectionClass, array $data): array
    {
        $ctor = $reflectionClass->getConstructor();
        if ($ctor === null) {
            return [];
        }
        $ctorArgs = [];
        foreach ($ctor->getParameters() as $reflectionParameter) {
            $pname = $reflectionParameter->getName();
            if (array_key_exists($pname, $data)) {
                $ctorArgs[] = $data[$pname];
            } elseif ($reflectionParameter->isDefaultValueAvailable()) {
                $ctorArgs[] = $reflectionParameter->getDefaultValue();
            } else {
                $ctorArgs[] = null;
            }
        }

        return $ctorArgs;
    }

    // ---------------------------------------------------------------------
    // 2) ValueObject building (buildVo + buildNestedVo)
    // ---------------------------------------------------------------------

    /**
     * Entry: build the requested VO class from $data (associative).
     *
     * @param  array<string, mixed>  $data
     */
    private function buildVo(array $data, string $voClass): object
    {
        if (! class_exists($voClass)) {
            throw new InvalidArgumentException("VO class {$voClass} not found");
        }

        $reflectionClass = new ReflectionClass($voClass);
        $ctor = $reflectionClass->getConstructor();
        $params = $ctor ? $ctor->getParameters() : [];

        $args = [];
        $runtimePreprocessors = [];
        $runtimeOptions = [];
        $contextIndex = null;

        foreach ($params as $param) {
            $name = $param->getName();

            // Track context parameter so we can merge runtime preprocessors/options later
            if ($this->isContextParameter($param)) {
                $contextIndex = count($args);
                if (array_key_exists($name, $data)) {
                    $args[] = $data[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } else {
                    $args[] = null;
                }

                continue;
            }

            if (! array_key_exists($name, $data)) {
                if ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();

                    continue;
                }
                throw new InvalidArgumentException("Missing property '{$name}' for {$voClass}");
            }

            $raw = $data[$name];
            $type = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : null;

            // ValueObject subclass -> build nested
            if ($typeName !== null && class_exists($typeName) && is_subclass_of($typeName, ValueObject::class)) {
                $args[] = $this->buildNestedVo($raw, $typeName);

                continue;
            }

            // enum constructor param
            if ($typeName !== null && enum_exists($typeName)) {
                $args[] = $this->buildEnumValue($typeName, $raw);

                continue;
            }

            // primitive or other: allow shorthand ['value'=>..] and runtime preprocessors/options
            if ($type instanceof ReflectionNamedType && $type->isBuiltin() && is_array($raw)) {
                $rawArray = $raw;

                if (array_key_exists('value', $rawArray)) {
                    $raw = $rawArray['value'];
                } else {
                    $firstNumKey = $this->firstNumericKey($rawArray);
                    if ($firstNumKey !== null) {
                        $raw = $rawArray[$firstNumKey];
                    }
                }

                if (isset($rawArray['runtimePreprocessor'])) {
                    $runtimePreprocessors[$name] = $rawArray['runtimePreprocessor'];
                }
                if (isset($rawArray['runtimeOption'])) {
                    $runtimeOptions[$name] = $rawArray['runtimeOption'];
                }
            }

            $args[] = $raw;
        }

        // Inject VOConstructionContext if runtime preprocessors/options were provided
        if ($contextIndex !== null && ($runtimePreprocessors !== [] || $runtimeOptions !== [])) {
            $existing = $args[$contextIndex] ?? null;
            $mergedPre = $runtimePreprocessors;
            $mergedOptions = $runtimeOptions;
            $skipResolve = false;

            if ($existing instanceof VOConstructionContext) {
                $mergedPre = array_replace($existing->runtimePreprocessors, $runtimePreprocessors);
                $mergedOptions = array_replace_recursive($existing->runtimeOptions, $runtimeOptions);
                $skipResolve = $existing->skipResolve;
            }

            $args[$contextIndex] = new VOConstructionContext(
                runtimePreprocessors: $mergedPre,
                runtimeOptions: $mergedOptions,
                skipResolve: $skipResolve
            );
        }

        return $reflectionClass->newInstanceArgs($args);
    }

    /**
     * Build a nested VO (constructor will be called directly).
     *
     * Supports many shorthand forms (see earlier discussion).
     */
    private function buildNestedVo(mixed $raw, string $voSubClass): object
    {
        // Keep guard for static analysis when $raw is not an object
        // @noRector RemoveUselessIsObjectCheckRector
        if ($raw instanceof $voSubClass) {
            return $raw;
        }

        $reflectionClass = new ReflectionClass($voSubClass);
        $voCtor = $reflectionClass->getConstructor();
        $voCtorParams = $voCtor ? $voCtor->getParameters() : [];

        // property names for validation
        $voPropNames = [];
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $voPropNames[$reflectionProperty->getName()] = true;
        }

        $voValues = [];
        $preprocessors = [];
        $options = [];

        if (
            is_array($raw)
            && $this->isAssoc($raw)
            && $this->arrayKeysOverlap($raw, array_keys($voPropNames))
        ) {
            foreach ($raw as $propName => $propSpec) {
                if (! array_key_exists($propName, $voPropNames)) {
                    throw new InvalidArgumentException("Property '{$propName}' does not exist on {$voSubClass}");
                }

                if (! is_array($propSpec)) {
                    $voValues[$propName] = $propSpec;

                    continue;
                }

                if (array_key_exists('value', $propSpec)) {
                    $voValues[$propName] = $propSpec['value'];
                    if (isset($propSpec['runtimePreprocessor'])) {
                        $preprocessors[$propName] = $propSpec['runtimePreprocessor'];
                    }
                    if (isset($propSpec['runtimeOption'])) {
                        $options[$propName] = $propSpec['runtimeOption'];
                    }
                } else {
                    // numeric-shorthand inside property
                    $firstNumKey = $this->firstNumericKey($propSpec);
                    if ($firstNumKey !== null) {
                        $voValues[$propName] = $propSpec[$firstNumKey];
                    }

                    if (isset($propSpec['runtimePreprocessor'])) {
                        $preprocessors[$propName] = $propSpec['runtimePreprocessor'];
                    }
                    if (isset($propSpec['runtimeOption'])) {
                        $options[$propName] = $propSpec['runtimeOption'];
                    }
                }
            }
        } else {
            // shorthand or single primitive
            if (is_array($raw)) {
                if (array_key_exists('value', $raw)) {
                    $mainVal = $raw['value'];
                    if (isset($raw['runtimePreprocessor'])) {
                        $preprocessors['__first'] = $raw['runtimePreprocessor'];
                    }
                    if (isset($raw['runtimeOption'])) {
                        $options['__first'] = $raw['runtimeOption'];
                    }
                } else {
                    $numKey = $this->firstNumericKey($raw);
                    $mainVal = $numKey !== null ? $raw[$numKey] : null;
                    if (isset($raw['runtimePreprocessor'])) {
                        $preprocessors['__first'] = $raw['runtimePreprocessor'];
                    }
                    if (isset($raw['runtimeOption'])) {
                        $options['__first'] = $raw['runtimeOption'];
                    }
                }
            } else {
                $mainVal = $raw;
            }

            if (isset($mainVal)) {
                $firstCtorParamName = $voCtorParams[0]->getName() ?? null;
                if ($firstCtorParamName === null) {
                    throw new InvalidArgumentException("VO {$voSubClass} has no constructor parameter to accept value");
                }
                $voValues[$firstCtorParamName] = $mainVal;

                if (isset($preprocessors['__first'])) {
                    $preprocessors[$firstCtorParamName] = $preprocessors['__first'];
                    unset($preprocessors['__first']);
                }
                if (isset($options['__first'])) {
                    $options[$firstCtorParamName] = $options['__first'];
                    unset($options['__first']);
                }
            }
        }

        // Validate preprocessor/option targets
        $validNames = $this->getVoPropertyOrCtorNames($reflectionClass);
        foreach (array_keys($preprocessors) as $pn) {
            if (! in_array($pn, $validNames, true)) {
                throw new InvalidArgumentException("Invalid runtimePreprocessor target '{$pn}' for VO {$voSubClass}");
            }
        }
        foreach (array_keys($options) as $pn) {
            if (! in_array($pn, $validNames, true)) {
                throw new InvalidArgumentException("Invalid runtimeOption target '{$pn}' for VO {$voSubClass}");
            }
        }

        // Build constructor args in order, inject context if present
        $ctorArgs = [];
        $hasContextParam = false;
        foreach ($voCtorParams as $reflectionProperty) {
            $pname = $reflectionProperty->getName();
            if ($pname === 'context') {
                $hasContextParam = true;
                $ctorArgs[] = null;

                continue;
            }

            if (array_key_exists($pname, $voValues)) {
                $ctorArgs[] = $voValues[$pname];
            } elseif ($reflectionProperty->isDefaultValueAvailable()) {
                $ctorArgs[] = $reflectionProperty->getDefaultValue();
            } else {
                throw new InvalidArgumentException("Missing value for {$pname} in {$voSubClass}");
            }
        }

        // Build VOConstructionContext only if needed
        $context = null;
        if ($preprocessors !== [] || $options !== []) {
            $context = new VOConstructionContext(runtimePreprocessors: $preprocessors, runtimeOptions: $options);
        }

        if ($hasContextParam) {
            foreach ($voCtorParams as $idx => $reflectionProperty) {
                if ($reflectionProperty->getName() === 'context') {
                    $ctorArgs[$idx] = $context;
                    break;
                }
            }
        }

        return $reflectionClass->newInstanceArgs($ctorArgs);
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * @param  array<mixed>  $arr
     */
    private function firstNumericKey(array $arr): ?int
    {
        foreach (array_keys($arr) as $k) {
            if (is_int($k)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * @param  ReflectionClass<object>  $reflectionClass
     * @return array<int, string>
     */
    private function getVoPropertyOrCtorNames(ReflectionClass $reflectionClass): array
    {
        $names = [];
        foreach ($reflectionClass->getProperties() as $reflectionParameter) {
            $names[] = $reflectionParameter->getName();
        }
        $ctor = $reflectionClass->getConstructor();
        if ($ctor) {
            foreach ($ctor->getParameters() as $reflectionParameter) {
                $names[] = $reflectionParameter->getName();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $arr
     * @param  array<int, string>  $candidates
     */
    private function arrayKeysOverlap(array $arr, array $candidates): bool
    {
        foreach (array_keys($arr) as $k) {
            if (in_array($k, $candidates, true)) {
                return true;
            }
        }

        return false;
    }

    private function isContextParameter(ReflectionParameter $reflectionParameter): bool
    {
        $type = $reflectionParameter->getType();

        return $reflectionParameter->getName() === 'context'
            && $type instanceof ReflectionNamedType
            && $type->getName() === VOConstructionContext::class;
    }

    // ---------------------------------------------------------------------
    // 3) Generic object builder (buildObjectFromConstructor)
    // ---------------------------------------------------------------------

    /**
     * Build a plain object (Command/Query/any class) directly from constructor mapping
     * Fallback used when static schema() is absent.
     *
     * @param  class-string  $class
     * @param  array<mixed>  $data  associative or positional
     */
    private function buildObjectFromConstructor(string $class, array $data = []): object
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Class {$class} not found");
        }

        $ref = new ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if ($ctor === null) {
            // no ctor — just instantiate
            return $ref->newInstance();
        }

        $params = $ctor->getParameters();
        $ctorArgs = [];

        // If data is not assoc but single scalar and there is one parameter -> map directly
        if (! $this->isAssoc($data) && count($params) === 1 && count($data) === 1) {
            $rawList = array_values($data);
            $data = [$params[0]->getName() => $rawList[0]];
        }

        foreach ($params as $param) {
            $pname = $param->getName();
            $raw = null;

            // prefer named value in provided data
            if ($this->isAssoc($data) && array_key_exists($pname, $data)) {
                $raw = $data[$pname];
            } elseif ($this->isAssoc($data)) {
                // case-insensitive fallback
                foreach ($data as $k => $v) {
                    if (is_string($k) && strcasecmp($k, $pname) === 0) {
                        $raw = $v;
                        break;
                    }
                }
            }

            // If still no raw and param type is a VO, try to detect if top-level data contains the VO's fields:
            $type = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : null;

            // if data contains a key matching the param name, use it (already handled above)
            // else if top-level assoc data has keys that overlap the VO properties -> create subset
            if ($raw === null && $typeName !== null && class_exists($typeName) && is_subclass_of($typeName, ValueObject::class) && $this->isAssoc($data)) {
                $voRef = new ReflectionClass($typeName);
                $voProps = array_map(fn (\ReflectionProperty $reflectionProperty): string => $reflectionProperty->getName(), $voRef->getProperties());
                $subset = [];
                foreach ($data as $k => $v) {
                    if (in_array($k, $voProps, true)) {
                        $subset[$k] = $v;
                    }
                }
                if ($subset !== []) {
                    $raw = $subset;
                }
            }

            // If still no raw, and data is numeric array positional -> try to use positional index
            if ($raw === null && ! $this->isAssoc($data)) {
                // find position of param
                $paramsList = $ctor->getParameters();
                $index = array_search($param, $paramsList, true);
                if ($index !== false && array_key_exists($index, $data)) {
                    $raw = $data[$index];
                }
            }

            // If still no raw, use default if available else error
            if ($raw === null) {
                if ($param->isDefaultValueAvailable()) {
                    $ctorArgs[] = $param->getDefaultValue();

                    continue;
                }
                throw new InvalidArgumentException("Missing value for constructor parameter '{$pname}' of {$class}");
            }

            // Now coerce based on parameter type:
            // 1) ValueObject subclass
            if ($typeName !== null && class_exists($typeName) && is_subclass_of($typeName, ValueObject::class)) {
                if ($raw instanceof $typeName) {
                    $ctorArgs[] = $raw;
                } elseif (is_array($raw)) {
                    $ctorArgs[] = $this->buildVo($raw, $typeName);
                } else {
                    $ctorArgs[] = $this->buildNestedVo($raw, $typeName);
                }

                continue;
            }

            // 2) enum type
            if ($typeName !== null && enum_exists($typeName)) {
                $ctorArgs[] = $this->buildEnumValue($typeName, $raw);

                continue;
            }

            // 3) other class type (non-VO)
            if ($typeName !== null && class_exists($typeName) && ! is_subclass_of($typeName, ValueObject::class)) {
                if ($raw instanceof $typeName) {
                    $ctorArgs[] = $raw;
                } elseif (is_array($raw)) {
                    $subRef = new ReflectionClass($typeName);
                    $ctorArgs[] = $subRef->newInstanceArgs($this->buildArgsForClass($subRef, $raw));
                } else {
                    try {
                        $ctorArgs[] = new $typeName($raw);
                    } catch (\Throwable) {
                        // fallback: raw as-is
                        $ctorArgs[] = $raw;
                    }
                }

                continue;
            }

            // 4) primitives and other -> pass as-is
            $ctorArgs[] = $raw;
        }

        return $ref->newInstanceArgs($ctorArgs);
    }

    // ---------------------------------------------------------------------
    // 4) Enum handling (new buildEnumValue at bottom of your trait)
    // ---------------------------------------------------------------------

    /**
     * Build/normalize enum instance for given enum class and raw input.
     *
     * Accepts:
     * - enum instance (returned as-is)
     * - scalar (string/int)
     * - shorthand array: ['value' => 'accepted', 'runtimePreprocessor' => fn(...) ]
     * - numeric shorthand: [ 'accepted', 'runtimePreprocessor' => fn(...) ]
     *
     * @param  class-string  $enumClass
     * @return \UnitEnum enum instance
     */
    private function buildEnumValue(string $enumClass, mixed $raw): \UnitEnum
    {
        if (! enum_exists($enumClass)) {
            throw new InvalidArgumentException("Enum {$enumClass} does not exist");
        }

        // If already an instance of enum, return it (guard non-object to avoid TypeError)
        // Keep guard for static analysis when $raw is not an object
        // @noRector RemoveUselessIsObjectCheckRector
        if ($raw instanceof $enumClass) {
            return $raw;
        }

        // Extract main value and runtimePreprocessor if raw is an array/shorthand
        $mainVal = $raw;
        $runtimePre = null;

        if (is_array($raw)) {
            // explicit 'value' key
            if (array_key_exists('value', $raw)) {
                $mainVal = $raw['value'];
                if (isset($raw['runtimePreprocessor'])) {
                    $runtimePre = $raw['runtimePreprocessor'];
                }
            } else {
                // numeric shorthand: find first numeric key
                $numKey = $this->firstNumericKey($raw);
                if ($numKey !== null) {
                    $mainVal = $raw[$numKey];
                } else {
                    // if associative but not 'value', maybe it's already the map for a different use — fallback to null
                    $mainVal = null;
                }
                if (isset($raw['runtimePreprocessor'])) {
                    $runtimePre = $raw['runtimePreprocessor'];
                }
            }
        }

        // Apply runtime preprocessor if provided
        if ($runtimePre !== null && is_callable($runtimePre)) {
            $mainVal = $runtimePre($mainVal);
        }

        // Now try to build/resolve the enum
        // 1) If backed enum, try tryFrom (safe)
        $cases = $enumClass::cases();

        // Helper to find matching case by name or backing value
        $matchByNameOrValue = function (mixed $candidate) use ($cases) {
            if ($candidate === null) {
                return null;
            }
            $candStr = is_string($candidate) || is_numeric($candidate) ? (string) $candidate : null;

            foreach ($cases as $case) {
                // match by case name (case-insensitive)
                if ($candStr !== null && strcasecmp($case->name, $candStr) === 0) {
                    return $case;
                }
                // if backed enum, match by backing value (string/int)
                if (property_exists($case, 'value')) {
                    $caseValue = $case->value;
                    if ($caseValue === $candidate) {
                        return $case;
                    }
                    if ($candStr !== null && strcasecmp((string) $caseValue, $candStr) === 0) {
                        return $case;
                    }
                }
            }

            return null;
        };

        // Try direct tryFrom (only works for backed enums)
        if (method_exists($enumClass, 'tryFrom')) {
            try {
                $enumInstance = $enumClass::tryFrom($mainVal);
                if ($enumInstance !== null) {
                    return $enumInstance;
                }
            } catch (\Throwable) {
                // ignore and continue with heuristics
            }
        }

        // try matching by name or by backing value
        $found = $matchByNameOrValue($mainVal);
        if ($found !== null) {
            return $found;
        }

        // Boolean heuristics: if input is bool, attempt common mappings
        if (is_bool($mainVal)) {
            // Common case: Terms enum with accepted/declined
            $names = array_map(fn (\UnitEnum $unitEnum): string => $unitEnum->name, $cases);
            if (in_array('accepted', $names, true) && in_array('declined', $names, true)) {
                return $mainVal ? $enumClass::accepted : $enumClass::declined;
            }

            // If backing type is int try 1/0
            foreach ($cases as $case) {
                if (property_exists($case, 'value') && is_int($case->value)) {
                    $candidate = $mainVal ? 1 : 0;
                    if (method_exists($enumClass, 'tryFrom')) {
                        $try = $enumClass::tryFrom($candidate);
                        if ($try !== null) {
                            return $try;
                        }
                    }
                    $found = $matchByNameOrValue($candidate);
                    if ($found !== null) {
                        return $found;
                    }
                    break;
                }
            }

            // fallback to strings 'true'/'false'
            $found = $matchByNameOrValue($mainVal ? 'true' : 'false');
            if ($found !== null) {
                return $found;
            }
        }

        // If candidate is `null` or empty, fail quickly
        if ($mainVal === null) {
            throw new InvalidArgumentException("Unable to construct enum {$enumClass} from null/empty value.");
        }

        // As last resort try matching after lowercasing/trimming typical truthy/falsy words
        $s = is_string($mainVal) || is_numeric($mainVal)
            ? strtolower(trim((string) $mainVal))
            : null;

        if ($s !== null) {
            $truthy = ['1', 'true', 'yes', 'y', 'accepted'];
            $falsy = ['0', 'false', 'no', 'n', 'declined'];

            if (
                in_array($s, $truthy, true)
                && in_array('accepted', array_map(fn (\UnitEnum $unitEnum): string => $unitEnum->name, $cases), true)
            ) {
                return $enumClass::accepted;
            }
            if (
                in_array($s, $falsy, true)
                && in_array('declined', array_map(fn (\UnitEnum $unitEnum): string => $unitEnum->name, $cases), true)
            ) {
                return $enumClass::declined;
            }

            // try again by name/backing value generalized
            $found = $matchByNameOrValue($s);
            if ($found !== null) {
                return $found;
            }
        }

        throw new InvalidArgumentException("Unable to construct enum {$enumClass} from value.");
    }
}
