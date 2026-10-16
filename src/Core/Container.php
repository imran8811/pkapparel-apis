<?php

declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Tiny dependency container. Classes are built by reading their constructor
 * type hints, and every resolved object is shared for the rest of the request.
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @param callable(self): object $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $object = isset($this->factories[$id]) ? ($this->factories[$id])($this) : $this->build($id);

        return $this->instances[$id] = $object;
    }

    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Container cannot resolve [{$class}]: class not found.");
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Container cannot resolve [{$class}]: not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $class();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new RuntimeException(
                    "Container cannot resolve parameter \${$parameter->getName()} of [{$class}]."
                );
            }
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
