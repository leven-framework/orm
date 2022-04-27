<?php

namespace Leven\ORM;

use Leven\DBA\Common\AdapterInterface;
use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException};

interface RepositoryInterface
{
    public function getDb(): AdapterInterface;
    public function getConfig(): RepositoryConfig;

    /**
     * @throws EntityNotFoundException
     */
    public function get(string $entityClass, string $primaryValue): Entity;

    public function try(string $entityClass, string $primaryValue): ?Entity;

    public function spawnEntityFromDbRow(string $entityClass, array $row): Entity;

    /**
     * @throws PropertyValidationException
     */
    public function update(Entity ...$entities): static;

    /**
     * @throws PropertyValidationException
     */
    public function store(Entity ...$entities): static;

    public function delete(string $entityClass, string $primaryValue): static;

    public function deleteEntity(Entity $entity): static;

    public function find(string $entityClass): Query;

    public function all(string $entityClass): Collection;

    public function findChildrenOf(Entity|array $parentEntities, string $childrenEntityClass): Query;

    public function txnBegin();

    public function txnCommit();

    public function txnRollback();
}