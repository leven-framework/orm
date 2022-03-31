<?php

namespace Leven\ORM\Converters;

use Leven\ORM\Exceptions\PropertyValidationException;

class JsonConverter extends BaseConverter
{

    /**
     * it's possible to extend this converter and
     * change this property so decoding will output stdClass object instead of array
     *
     * @var bool
     */
    protected bool $associativeDecode = true;

    /**
     * @throws PropertyValidationException
     */
    public function convertForDatabase($value): string
    {
        $encoded = json_encode($value);

        if($encoded === false)
            throw new PropertyValidationException("json encode failed for $this->propName");

        return $encoded;
    }

    /**
     * @throws PropertyValidationException
     */
    public function convertForPhp($value): mixed
    {
        $decoded = json_decode($value, $this->associativeDecode);

        if(json_last_error() !== 0)
            throw new PropertyValidationException("json decode failed for $this->propName");

        return $decoded;
    }

}