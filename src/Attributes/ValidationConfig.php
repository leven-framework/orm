<?php

namespace Leven\ORM\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidationConfig
{

    public function __construct(
        public bool $notEmpty = false,
        public bool $noHTML = false,
        public ?PropType $type = null,
        public ?int $filter = null,
        public ?string $regex = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?int $min = null,
        public ?int $max = null,
    )
    {

    }

}

