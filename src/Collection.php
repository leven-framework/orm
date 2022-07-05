<?php

namespace Leven\ORM;

use ArrayIterator, ArrayObject, IteratorAggregate;
use InvalidArgumentException;

class Collection implements IteratorAggregate
{

    protected array $store = [];

    public function __construct(
        protected string $class,
        Entity ...$entities
    )
    {
        foreach($entities as $entity) $this->add($entity);
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getIterator(): ArrayIterator
    {
        return (new ArrayObject($this->store))->getIterator();
    }

    public function add(Entity $entity): static
    {
        if(!$entity instanceof $this->class)
            throw new InvalidArgumentException("object is not instance of $this->class");

        $this->store[] = $entity;
        return $this;
    }

    public function filter(callable $callback): static
    {
        $collection = clone $this;
        $collection->store = array_filter($collection->store, $callback);
        return $collection;
    }

    public function reject(callable $callback): static
    {
        return $this->filter( fn ($entity) => !$callback($entity) );
    }

    public function each(callable $callback): static
    {
        foreach($this->store as $entity) $callback($entity);
        return $this;
    }

    public function map(callable $callback): array
    {
        return array_map($callback, $this->store);
    }

    public function mapAssoc(callable $callback): array
    {
        foreach($this->store as $entity) {
            [$key, $value] = $callback($entity);
            $out[$key] = $value;
        }
        return $out ?? [];
    }

    public function empty(): bool
    {
        return empty($this->store);
    }

    public function count(): int
    {
        return count($this->store);
    }

    public function array(): array
    {
        return $this->store;
    }

}