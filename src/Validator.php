<?php namespace Leven\ORM;

use Leven\ORM\Attributes\{PropType, ValidationConfig};
use Leven\ORM\Exceptions\PropertyValidationException;

class Validator
{

    public function __construct(
        private ValidationConfig $config
    )
    {
    }

    /**
     * @throws PropertyValidationException
     */
    public function __invoke(int|string|bool|null $value): void
    {
        $c = $this->config;

        if ($c->notEmpty && $value === '')
            throw new PropertyValidationException($name . ' failed: notEmpty');
        if ($c->noHTML && strip_tags($value) != $value)
            throw new PropertyValidationException($name . ' failed: noHTML');

        if ($c->type === PropType::AlphaNumeric && !ctype_alnum($value))
            throw new PropertyValidationException($name . ' failed: type=AlphaNumeric');
        else if ($c->type === PropType::Alphabetic && !ctype_alpha($value))
            throw new PropertyValidationException($name . ' failed: type=Alphabetic');
        else if ($c->type === PropType::Numeric && !ctype_digit($value))
            throw new PropertyValidationException($name . ' failed: type=Numeric');

        if ($c->filter && !filter_var($value, $c->filter))
            throw new PropertyValidationException($name . ' failed: filter');
        if ($c->regex && !preg_match($c->regex, $value))
            throw new PropertyValidationException($name . ' failed: regex');

        if (isset($c->minLength) && strlen($value) < $c->minLength)
            throw new PropertyValidationException($name . ' failed: minLength');
        if (isset($c->maxLength) && strlen($value) > $c->maxLength)
            throw new PropertyValidationException($name . ' failed: maxLength');

        if (isset($c->min) && $value < $c->min)
            throw new PropertyValidationException($name . ' failed: min');
        if (isset($c->max) && $value > $c->max)
            throw new PropertyValidationException($name . ' failed: max');
    }

}