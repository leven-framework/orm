<?php

namespace Leven\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class PropConfig
{

    public string $name;
    public bool $parent = false;
    public bool $inConstructor = false;
    public ?string $typeClass = null;
    public ValidationConfig $validation;

    public function __construct(
        public bool $index = false,
        public ?string $column = null,
        public bool $primary = false,
        public ?string $converter = null,
        public array $custom = [],
    )
    {
        if($this->primary) $this->index = true;
    }

    public function generateColumn(string $prop): void
    {
        $this->column = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $prop));
    }

}