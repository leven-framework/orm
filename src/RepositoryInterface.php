<?php

namespace Leven\ORM;

use Leven\DBA\Common\AdapterInterface;
use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException, RepositoryDatabaseException};

interface RepositoryInterface
{
    public function getDb(): AdapterInterface;
    public function getConfig(): RepositoryConfig;

    /**
     * @throws EntityNotFoundException
     * @throws RepositoryDatabaseException
     */
    public function get(string $entityClass, string $primaryValue): Entity;

    public function spawnEntityFromDbRow(string $entityClass, array $row): Entity;

    /**
     * @throws PropertyValidationException
     * @throws RepositoryDatabaseException
     */
    public function update(Entity ...$entities): static;

    /**
     * @throws PropertyValidationException
     * @throws RepositoryDatabaseException
     */
    public function store(Entity ...$entities): static;

    public function delete(string $entityClass, string $primaryValue): static;

    public function deleteEntity(Entity $entity): static;

    public function find(string $entityClass): Query;

    public function all(string $entityClass): Query;

    public function findChildrenOf(Entity|array $parentEntities, string $childrenEntityClass): Query;

    /**
     * @throws RepositoryDatabaseException
     */
    public function txnBegin();

    /**
     * @throws RepositoryDatabaseException
     */
    public function txnCommit();

    /**
     * @throws RepositoryDatabaseException
     */
    public function txnRollback();
}