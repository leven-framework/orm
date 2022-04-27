<?php

namespace Leven\ORM\Attributes;

use Attribute;
use Leven\ORM\Exceptions\EntityNotFoundException;

#[Attribute(Attribute::TARGET_CLASS)]
class EntityConfig
{

    public string $class;

    /** @var PropConfig[] $props */
    private array $props = [];
    public string $primaryProp;
    public array $columns = [];
    public array $parentColumns = [];
    public array $constructorProps = [];

    public function __construct(
        public ?string $table = null,
        public string $propsColumn = 'props',
        public string $notFoundException = EntityNotFoundException::class
    )
    {
    }

    public function addProp(PropConfig $prop): void
    {
        $this->props[$prop->name] = $prop;
        $this->columns[$prop->column] = $prop->name;

        if($prop->primary) $this->primaryProp = $prop->name;
        if($prop->inConstructor) $this->constructorProps[] = $prop->name;
        if($prop->parent) $this->parentColumns[$prop->typeClass] = $prop->column;
    }

    public function getProp(?string $name): PropConfig
    {
        if(!isset($this->props[$name]))
            throw new \Exception("prop $name does not exist");

        return $this->props[$name];
    }

    public function getProps(): array
    {
        return $this->props;
    }

    public function getPrimaryColumn(): string
    {
        return $this->props[$this->primaryProp]->column;
    }

    public function generateTable(string $class): void
    {
        $this->table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }

}