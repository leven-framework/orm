<?php namespace Leven\ORM\Converters;

use DateTime;

final class DateTimeStringConverter extends BaseConverter
{

    public function convertForDatabase($value): string
    {
        /** @var DateTime $value */
        return $value->format('c');
    }

    public function convertForPhp($value): DateTime
    {
        /** @var string $value */
        return new DateTime($value);
    }

}