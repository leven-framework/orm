<?php

namespace Leven\ORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ValidationConfig
{

    public function __construct(
        public readonly bool $notEmpty = false,
        public readonly bool $noHTML = false,
        public readonly ?PropType $type = null,
        public readonly ?int $filter = null,
        public readonly ?string $regex = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    )
    {

    }

}

