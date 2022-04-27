<?php

namespace Leven\ORM;

use DomainException;
use Leven\DBA\Common\AdapterResponse;
use Leven\DBA\Common\BuilderPart\WhereGroup;
use Leven\DBA\Common\SelectQueryInterface;
use Leven\ORM\Attributes\EntityConfig;
use Leven\ORM\Exceptions\EntityNotFoundException;

final class Query
{

    public readonly SelectQueryInterface $dbQuery;
    protected readonly WhereGroup $conditions;

    public function __construct(
        protected RepositoryInterface $repo,
        protected string $class
    ){
        $this->dbQuery = $this->repo->getDb()
            ->select($this->getEntityConfig()->table)
            ->columns($this->getEntityConfig()->propsColumn)
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

    public function where(string $prop, $valueOrOperator, $value = []): Query
    {
        $this->conditions->where($this->getPropColumn($prop), $valueOrOperator, $value);
        return $this;
    }

    // RESULT //

    public function count(): int
    {
        return $this->execute()->count;
        // TODO return $this->dbQuery->executeCount();
    }

    public function get(): Collection
    {
        foreach ($this->execute()->rows as $row)
            $entities[] = $this->repo->spawnEntityFromDbRow($this->class, $row);

        return new Collection($this->class, ...($entities ?? []));
    }

    public function getFirst(): Entity
    {
        return $this->tryFirst() ?? throw new EntityNotFoundException;
    }

    public function tryFirst(): ?Entity
    {
        $rows = $this->execute()->rows;
        if(!isset($rows[0])) return null;

        return $this->repo->spawnEntityFromDbRow($this->class, $rows[0]);
    }

    // INTERNAL //

    protected function execute(): AdapterResponse
    {
        // TODO prevent multiple executions
        return $this->dbQuery->execute();
    }

    protected function getEntityConfig(): EntityConfig
    {
        return $this->repo->getConfig()->for($this->class);
    }

    protected function getPropColumn(string $prop): string
    {
        return $this->getEntityConfig()->getProp($prop)->column
            ?: throw new DomainException("prop $prop or its column not configured");
    }

}
