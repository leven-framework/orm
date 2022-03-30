<?php

namespace Leven\ORM\Converters;

use Leven\ORM\Repository;
use Leven\ORM\Attributes\PropConfig;

abstract class BaseConverter
{

    public function __construct(
        public Repository $repo,
        public string $entityClass,
        public string $propName,
    )
    {
    }

    abstract public function convertForDatabase($value): string|int|bool|null;

    abstract public function convertForPhp($value): mixed;

    protected function getPropConfig(): PropConfig
    {
        return $this->repo->getConfig()->for($this->entityClass)->getProp($this->propName);
    }

}