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
        foreach($entities as $entity)
            $this->add($entity);
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

    public function reject(callable $callback): static
    {
        foreach($this->store as $index => $entity)
            if($callback($entity) === true)
                unset($this->store[$index]);

        return $this;
    }

    public function array(): array
    {
        return $this->store;
    }

    public function arrayOfProps($propName): array
    {
        foreach($this->store as $entity)
            $out[] = $entity->$propName;
        return $out ?? [];
    }

}