<?php

namespace Leven\ORM\Converters;

use Leven\ORM\Attributes\PropConfig;
use Leven\ORM\RepositoryInterface;

abstract class BaseConverter
{

    public function __construct(
        public RepositoryInterface $repo,
        public string $entityClass,
        public string $propName,
    )
    {
    }

    final protected function getPropConfig(): PropConfig
    {
        return $this->repo->getConfig()->for($this->entityClass)->getProp($this->propName);
    }

    abstract public function convertForDatabase($value): null|bool|string|int|float;

    abstract public function convertForPhp($value): mixed;

}