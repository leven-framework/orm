<?php

namespace Leven\ORM\Converter;

use Leven\ORM\Exception\PropertyValidationException;
use UnitEnum;

class EnumConverter extends BaseConverter
{

    public function convertForDatabase($value): ?string
    {
        return $value?->name;
    }

    public function convertForPhp($value): ?UnitEnum
    {
        if($value === null) return null;

        foreach(($this->getPropConfig()->typeClass)::cases() as $case)
            if($case->name === $value)
                return $case;

        throw new PropertyValidationException("can't convert $this->propName to enum, case doesn't exist");
    }

}