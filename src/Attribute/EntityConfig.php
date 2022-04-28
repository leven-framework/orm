<?php

namespace Leven\ORM\Attribute;

use Attribute;
use DomainException;
use Leven\ORM\Exception\EntityNotFoundException;

#[Attribute(Attribute::TARGET_CLASS)]
class EntityConfig
{

    public readonly string $class;

    /** @var PropConfig[] $props */
    private array $props = [];

    public readonly string $primaryProp;

    public array $columns = [];
    public array $parentColumns = [];
    public array $constructorProps = [];

    public function __construct(
        public ?string $table = null,
        public readonly string $propsColumn = 'props',
        public readonly string $notFoundException = EntityNotFoundException::class
    )
    {
    }

    public function generateTable(string $class): void
    {
        $this->table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }

    public function getProps(): array
    {
        return $this->props;
    }

    public function addProp(PropConfig $prop): void
    {
        $this->props[$prop->name] = $prop;
        $this->columns[$prop->column] = $prop->name;

        if($prop->primary) $this->primaryProp = $prop->name;
        if($prop->inConstructor) $this->constructorProps[] = $prop->name;
        if($prop->parent) $this->parentColumns[$prop->typeClass] = $prop->column;
    }

    public function getPropConfig(?string $name): PropConfig
    {
        return $this->props[$name] ??
            throw new DomainException("prop $name not configured in entity");
    }


    public function getPrimaryColumn(): string
    {
        return $this->props[$this->primaryProp]->column;
    }

}