<?php

namespace Leven\ORM\Converters;

class SerializeConverter extends BaseConverter
{

    /**
     * it's possible to extend this converter and
     * change this property to change the unserialize option
     *
     * @var bool|array
     */
    protected bool|array $allowedClasses = true;

    public function convertForDatabase($value): string
    {
        return serialize($value);
    }

    public function convertForPhp($value): mixed
    {
        return unserialize($value, ['allowed_classes' => $this->allowedClasses]);
    }

}