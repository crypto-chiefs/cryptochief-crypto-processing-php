<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Base class for SDK DTOs.
 *
 * Public PHP properties are camelCase; the wire format is snake_case. `toWire()` and
 * `fromWire()` convert between the two via reflection. `null` fields are dropped on the
 * way out so callers can leave optional fields unset, and unknown keys on the way in are
 * tolerated so the SDK survives new server fields.
 *
 * Subclasses declare their fields with constructor property promotion. Nested DTO and
 * `Asset[]` style list fields are walked recursively via PHP type hints.
 */
abstract class BaseDto implements \JsonSerializable
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private static array $reflCache = [];

    /** @var array<class-string, array<string, string>> */
    private static array $listElementCache = [];

    /**
     * @param array<string, mixed> $wire
     * @return static
     */
    public static function fromWire(array $wire): static
    {
        $refl = new ReflectionClass(static::class);
        $ctor = $refl->getConstructor();
        $args = [];

        // No constructor or no parameters: assign properties directly.
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            /** @var static $instance */
            $instance = $refl->newInstanceWithoutConstructor();
            foreach (self::props(static::class) as $camel => $prop) {
                $snake = self::toSnake($camel);
                if (!array_key_exists($snake, $wire)) {
                    continue;
                }
                $value = $wire[$snake];
                // A JSON null against a type that does not accept one: leave the property
                // at its declared value rather than throwing. See fromWire() below.
                if ($value === null && !($prop->getType()?->allowsNull() ?? true)) {
                    continue;
                }
                $prop->setValue($instance, self::coerceValue($prop, $value));
            }
            return $instance;
        }

        foreach ($ctor->getParameters() as $param) {
            $camel = $param->getName();
            $snake = self::toSnake($camel);
            if (array_key_exists($snake, $wire) && !self::isRejectedNull($param, $wire[$snake])) {
                $args[$camel] = self::coerceConstructorParam($param, $wire[$snake]);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[$camel] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[$camel] = null;
            } else {
                // Required field missing from the response - fall back to a typed zero.
                $args[$camel] = self::zeroForParam($param);
            }
        }

        return $refl->newInstanceArgs($args);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $out = [];
        foreach (self::props(static::class) as $camel => $prop) {
            $value = $prop->getValue($this);
            if ($value === null) {
                continue;
            }
            $out[self::toSnake($camel)] = self::wireValue($value);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toWire();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toWire();
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function wireValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toWire();
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $out[$k] = self::wireValue($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * @return array<string, ReflectionProperty>
     */
    private static function props(string $class): array
    {
        if (!isset(self::$reflCache[$class])) {
            $refl = new ReflectionClass($class);
            $map = [];
            foreach ($refl->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
                if ($p->isStatic()) {
                    continue;
                }
                $map[$p->getName()] = $p;
            }
            self::$reflCache[$class] = $map;
        }
        return self::$reflCache[$class];
    }

    /**
     * Whether a wire `null` has to be ignored because the target does not accept one.
     *
     * Go marshals an empty slice or map as JSON `null`, so `"tickers": null` means "no
     * tickers", not "wrong type". Passing that straight through would be a TypeError
     * against a non-nullable `array` parameter - a decode failure where the honest answer
     * is an empty list. Ignoring it hands the caller the declared default instead, which
     * for every list-shaped field here is `[]`.
     *
     * @param mixed $value
     */
    private static function isRejectedNull(\ReflectionParameter $param, mixed $value): bool
    {
        return $value === null && !$param->allowsNull();
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerceValue(ReflectionProperty $prop, mixed $value): mixed
    {
        $type = $prop->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return self::coerceNamedType($type->getName(), $value);
        }
        if ($type instanceof ReflectionNamedType && $type->getName() === 'array' && is_array($value)) {
            $element = self::detectListElement($prop->getDeclaringClass()->getName(), $prop->getName());
            if ($element !== null) {
                return self::coerceList($element, $value);
            }
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerceConstructorParam(\ReflectionParameter $param, mixed $value): mixed
    {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return self::coerceNamedType($type->getName(), $value);
        }
        if ($type instanceof ReflectionNamedType && $type->getName() === 'array' && is_array($value)) {
            $element = self::detectListElement(
                $param->getDeclaringClass()?->getName() ?? '',
                $param->getName()
            );
            if ($element !== null) {
                return self::coerceList($element, $value);
            }
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $t) {
                if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                    try {
                        return self::coerceNamedType($t->getName(), $value);
                    } catch (\Throwable) {
                        // fall through to the next type
                    }
                }
            }
        }
        return $value;
    }

    /**
     * @param class-string $element
     * @param array<mixed> $value
     * @return array<int, mixed>
     */
    private static function coerceList(string $element, array $value): array
    {
        $out = [];
        foreach ($value as $item) {
            $out[] = self::coerceNamedType($element, $item);
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function coerceNamedType(string $typeName, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_subclass_of($typeName, self::class)) {
            if (is_array($value)) {
                return $typeName::fromWire($value);
            }
            return $value;
        }
        if (is_subclass_of($typeName, \BackedEnum::class)) {
            if ($value instanceof $typeName) {
                return $value;
            }
            try {
                /** @var \BackedEnum $typeName */
                return $typeName::from($value);
            } catch (\Throwable) {
                return $value;
            }
        }
        return $value;
    }

    /**
     * Parse `@param Asset[]|null $allow`-style docblock hints to recover list element
     * types. Tagged properties get their elements coerced to the named DTO on fromWire().
     */
    private static function detectListElement(string $class, string $propName): ?string
    {
        if ($class === '') {
            return null;
        }
        if (!isset(self::$listElementCache[$class])) {
            self::$listElementCache[$class] = self::parseListDocblocks($class);
        }
        return self::$listElementCache[$class][$propName] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function parseListDocblocks(string $class): array
    {
        $refl = new ReflectionClass($class);
        $ctor = $refl->getConstructor();
        if ($ctor === null) {
            return [];
        }
        $doc = $ctor->getDocComment();
        if ($doc === false) {
            return [];
        }
        $namespace = $refl->getNamespaceName();
        $uses = self::parseUseStatements($refl->getFileName() ?: '');

        $out = [];
        if (preg_match_all(
            '/@param\s+([^\s]+)\s+\$([a-zA-Z_][a-zA-Z0-9_]*)/',
            $doc,
            $m,
            PREG_SET_ORDER
        )) {
            foreach ($m as $hit) {
                $rawType = $hit[1];
                $param = $hit[2];
                $element = self::resolveListElementType($rawType, $namespace, $uses);
                if ($element !== null) {
                    $out[$param] = $element;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $uses
     */
    private static function resolveListElementType(string $rawType, string $namespace, array $uses): ?string
    {
        // Strip null union: "Asset[]|null" or "?Asset[]" or "null|Asset[]"
        $parts = preg_split('/\s*\|\s*/', $rawType) ?: [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === 'null' || $p === '') {
                continue;
            }
            if (str_starts_with($p, '?')) {
                $p = substr($p, 1);
            }
            if (str_ends_with($p, '[]')) {
                $element = substr($p, 0, -2);
                return self::qualifyClass($element, $namespace, $uses);
            }
            // array<Asset> / list<Asset>
            if (preg_match('/^(?:array|list)<\s*([^>\s,]+)\s*>$/', $p, $am)) {
                return self::qualifyClass($am[1], $namespace, $uses);
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $uses
     */
    private static function qualifyClass(string $name, string $namespace, array $uses): ?string
    {
        $name = ltrim($name, '\\');
        if (isset($uses[$name])) {
            $candidate = $uses[$name];
        } elseif ($namespace !== '' && class_exists($namespace . '\\' . $name)) {
            $candidate = $namespace . '\\' . $name;
        } elseif (class_exists($name)) {
            $candidate = $name;
        } else {
            return null;
        }
        return $candidate;
    }

    /**
     * @return array<string, string>
     */
    private static function parseUseStatements(string $file): array
    {
        if ($file === '' || !is_readable($file)) {
            return [];
        }
        $src = (string) file_get_contents($file);
        $out = [];
        if (preg_match_all('/^\s*use\s+([^;]+);/m', $src, $m)) {
            foreach ($m[1] as $clause) {
                foreach (explode(',', $clause) as $piece) {
                    $piece = trim($piece);
                    if ($piece === '') {
                        continue;
                    }
                    if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $piece, $am)) {
                        $out[trim($am[2])] = ltrim(trim($am[1]), '\\');
                    } else {
                        $segments = explode('\\', $piece);
                        $alias = end($segments);
                        $out[$alias] = ltrim($piece, '\\');
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @return mixed
     */
    private static function zeroForParam(\ReflectionParameter $param): mixed
    {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType) {
            return match ($type->getName()) {
                'string' => '',
                'int'    => 0,
                'float'  => 0.0,
                'bool'   => false,
                'array'  => [],
                default  => null,
            };
        }
        return null;
    }

    public static function toSnake(string $name): string
    {
        $out = preg_replace('/[A-Z]/', '_$0', $name) ?? $name;
        return strtolower(ltrim($out, '_'));
    }
}
