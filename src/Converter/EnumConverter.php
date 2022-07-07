<?php

namespace Leven\ORM\Converter;

use Leven\ORM\Exception\PropertyValidationException;
use UnitEnum;

class EnumConverter extends BaseConverter
{

    public function convertForDatabase($value): ?string
    {
        if($value === null) return null;
        return strtolower($value->name);
    }

    public function convertForPhp($value): ?UnitEnum
    {
        if($value === null) return null;

        foreach(($this->getPropConfig()->typeClass)::cases() as $case)
            if($value === strtolower($case->name))
                return $case;

        throw new PropertyValidationException("can't convert $this->propName to enum, case doesn't exist");
    }

}