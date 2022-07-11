<?php

namespace Leven\ORM;

use DomainException;
use Leven\DBA\Common\AdapterResponse;
use Leven\DBA\Common\BuilderPart\DefaultValue;
use Leven\DBA\Common\BuilderPart\WhereGroup;
use Leven\DBA\Common\SelectQueryInterface;
use Leven\ORM\Attribute\EntityConfig;
use Leven\ORM\Exception\EntityNotFoundException;

final class Query
{

    public readonly SelectQueryInterface $dbQuery;
    protected readonly WhereGroup $conditions;

    public function __construct(
        protected RepositoryInterface $repo,
        protected string $class
    ){
        $entityConfig = $this->getEntityConfig();

        $this->dbQuery = $this->repo->getDb()
            ->select($entityConfig->table)
            ->columns($entityConfig->getPrimaryColumn(), $entityConfig->propsColumn)
        ;

        // we'll be putting all user conditions in this group
        $this->dbQuery->where(fn(WhereGroup $w) => $this->conditions = $w);
    }

    // BUILDER //

    public function limit(int $limitOrOffset, int $limit = 0): Query
    {
        $this->dbQuery->limit($limitOrOffset, $limit);
        return $this;
    }

    public function orderAsc(string $prop): Query
    {
        $this->dbQuery->orderAsc($this->getPropColumn($prop));
        return $this;
    }

    public function orderDesc(string $prop): Query
    {
        $this->dbQuery->orderDesc($this->getPropColumn($prop));
        return $this;
    }

    public function where(string $prop, $valueOrOperator, $value = new DefaultValue): Query
    {
        $this->conditions->where($this->getPropColumn($prop), $valueOrOperator, $value);
        return $this;
    }

    // RESULT //

    public function count(): int
    {
        return $this->execute()->count;
        // TODO return $this->dbQuery->executeCount() when I create it
    }

    public function get(): Collection
    {
        return new Collection($this->class, ...$this->repo->generateEntities($this->getEntityConfig(), $this->execute()));
    }

    public function getFirst(): Entity
    {
        return $this->tryFirst() ?? throw new EntityNotFoundException;
    }

    public function tryFirst(): ?Entity
    {
        $this->dbQuery->limit(1);
        return $this->repo->generateEntities( $this->getEntityConfig(), $this->execute() )[0] ?? null;
    }

    // INTERNAL //

    protected function execute(): AdapterResponse
    {
        // TODO prevent multiple executions
        return $this->dbQuery->execute();
    }

    protected function getEntityConfig(): EntityConfig
    {
        return $this->repo->getEntityConfig($this->class);
    }

    protected function getPropColumn(string $prop): string
    {
        return $this->getEntityConfig()->getPropConfig($prop)->column
            ?: throw new DomainException("prop $prop or its column not configured");
    }

}
