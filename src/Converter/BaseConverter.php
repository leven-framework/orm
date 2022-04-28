<?php

namespace Leven\ORM\Converter;

use Leven\ORM\Attribute\PropConfig;
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
        return $this->repo->getEntityConfig($this->entityClass)->getPropConfig($this->propName);
    }

    abstract public function convertForDatabase($value): null|bool|string|int|float;

    abstract public function convertForPhp($value): mixed;

}