<?php namespace Leven\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class PropConfig
{

    public string $name;
    public string $parent;
    public ValidationConfig $validation;

    public function __construct(
        public bool $index = false,
        public ?string $column = null,
        public bool $primary = false,
        public ?string $converter = null,
        public bool $serialize = false,
        public bool $jsonize = false
    )
    {
        if($this->primary) $this->index = true;
    }

    public function setColumnFromPropName(string $prop): void
    {
        $this->column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $prop));
    }

}