<?php namespace Leven\ORM;

use Leven\ORM\Attributes\{PropType, ValidationConfig};
use Leven\ORM\Exceptions\PropertyValidationException as PropValExc;

class Validator
{

    public function __construct(
        private ValidationConfig $config
    )
    {
    }

    /**
     * @throws PropValExc
     */
    public function validate(mixed $value, string $name = ''): void
    {
        if(!is_string($value) && !is_bool($value) && !is_numeric($value) && !is_null($value))
            throw new PropValExc("$name is neither string/bool/null/number, can't be stored in database");

        $c = $this->config;

        if ($c->notEmpty && $value === '')
            throw new PropValExc("$name failed: notEmpty");
        if ($c->noHTML && strip_tags($value) != $value)
            throw new PropValExc("$name failed: noHTML");

        if ($c->type === PropType::AlphaNumeric && !ctype_alnum($value))
            throw new PropValExc("$name failed: type=AlphaNumeric");
        else if ($c->type === PropType::Alphabetic && !ctype_alpha($value))
            throw new PropValExc("$name failed: type=Alphabetic");
        else if ($c->type === PropType::Numeric && !ctype_digit($value))
            throw new PropValExc("$name failed: type=Numeric");

        if ($c->filter && !filter_var($value, $c->filter))
            throw new PropValExc("$name failed: filter");
        if ($c->regex && !preg_match($c->regex, $value))
            throw new PropValExc("$name failed: regex");

        if (isset($c->minLength) && strlen($value) < $c->minLength)
            throw new PropValExc("$name failed: minLength");
        if (isset($c->maxLength) && strlen($value) > $c->maxLength)
            throw new PropValExc("$name failed: maxLength");

        if (isset($c->min) && $value < $c->min)
            throw new PropValExc("$name failed: min");
        if (isset($c->max) && $value > $c->max)
            throw new PropValExc("$name failed: max");
    }

}