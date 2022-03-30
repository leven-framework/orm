<?php

namespace Leven\ORM\Converters;

use Leven\ORM\Repository;

abstract class BaseConverter
{

    public function __construct(
        public Repository $repo
    )
    {
    }

    abstract public function convertForDatabase($value): string|int|bool|null;

    abstract public function convertForPhp($value): mixed;

}