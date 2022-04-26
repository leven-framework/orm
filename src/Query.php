<?php namespace Leven\ORM;

use Leven\DBA\Common\SelectQueryInterface;
use Leven\ORM\Attributes\EntityConfig;
use Leven\ORM\Exceptions\EntityNotFoundException;

final class Query
{

    public readonly SelectQueryInterface $dbQuery;

    public function __construct(
        protected RepositoryInterface $repo,
        protected string $class,
        array $conditions = [],
    ){
        $this->dbQuery = $this->repo->getDb()
            ->select($this->getEntityConfig()->table)
            ->columns($this->getEntityConfig()->propsColumn)
        ;

        foreach ($conditions as $prop => $value)
            $this->dbQuery->where($this->getPropColumn($prop), $value);

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


    // RESULT //

    public function count(): int
    {
        return $this->dbQuery->execute()->count;
        // TODO return $this->dbQuery->executeCount();
    }

    public function get(): Collection
    {
        foreach ($this->dbQuery->execute()->rows as $row)
            $entities[] = $this->repo->spawnEntityFromDbRow($this->class, $row);

        return new Collection($this->class, ...($entities ?? []));
    }

    public function getFirst(): Entity
    {
        return $this->tryFirst() ?? throw new EntityNotFoundException;
    }

    public function tryFirst(): ?Entity
    {
        $rows = $this->dbQuery->execute()->rows;
        if(!isset($rows[0])) return null;

        return $this->repo->spawnEntityFromDbRow($this->class, $rows[0]);
    }

    // INTERNAL //

    protected function getEntityConfig(): EntityConfig
    {
        return $this->repo->getConfig()->for($this->class);
    }

    protected function getPropColumn(string $prop): string
    {
        $column = $this->getEntityConfig()->getProp($prop)->column ?? '';
        if(empty($column)) throw new \Exception("prop $prop or its column not configured");
        return $column;
    }

}
