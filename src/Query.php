<?php namespace Leven\ORM;

use Leven\DBA\Common\{DatabaseAdapterResponse, Exception\DatabaseAdapterException};
use Leven\ORM\Attributes\EntityConfig;
use Leven\ORM\Exceptions\{EntityNotFoundException, RepositoryDatabaseException};

final class Query
{

    protected array $conditions;
    protected string $order;
    protected string $orderProp;
    protected int $limit;
    protected int $offset;

    public function __construct(
        protected Repository $repo,
        protected string $class,
        array $conditions
    ){
        $this->conditions = $this->replaceConditionsPropsWithColumns($conditions);
    }

    // BUILDER //

    public function limit(int $limit, ?int $offset = null): Query
    {
        $this->limit = $limit;
        if($offset > 0) $this->offset = $offset;
        return $this;
    }

    public function orderAsc(string $prop): Query
    {
        $this->order = 'ASC';
        $this->orderProp = $this->getPropColumn($prop);
        return $this;
    }

    public function orderDesc(string $prop): Query
    {
        $this->order = 'DESC';
        $this->orderProp = $this->getPropColumn($prop);
        return $this;
    }

    // RESULT //

    public function get(): Collection
    {
        $entities = [];
        foreach ($this->getFromRepoDB()->rows as $row){
            $props = $this->repo->parsePropsFromDbRow($this->class, $row);
            $entities[] = $this->repo->spawnEntityFromProps($this->class, $props);
        }
        return new Collection($this->class, ...$entities);
    }

    public function getFirst(): Entity
    {
        $rows = $this->getFromRepoDB()->rows;
        if(!isset($rows[0])) throw new EntityNotFoundException;

        $props = $this->repo->parsePropsFromDbRow($this->class, $rows[0]);
        return $this->repo->spawnEntityFromProps($this->class, $props);
    }

    // INTERNAL //

    private function getEntityConfig(): EntityConfig
    {
        return $this->repo->getConfig()->for($this->class);
    }

    private function getPropColumn(string $prop): string
    {
        $column = $this->getEntityConfig()->getProp($prop)->column ?? '';
        if(empty($column)) throw new \Exception("prop $prop or its column not configured");
        return $column;
    }

    private function replaceConditionsPropsWithColumns(array $conditions): array
    {
        $newConditions = [];
        foreach ($conditions as $prop => $value)
            $newConditions[$this->getPropColumn($prop)] = $value;
        return $newConditions;
    }

    private function getFromRepoDB(): DatabaseAdapterResponse
    {
        try {
            return $this->repo->getDb()->get(
                table: $this->getEntityConfig()->table,
                columns: $this->getEntityConfig()->propsColumn,
                conditions: $this->conditions,
                options: $this->generateOptions()
            );
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }

    private function generateOptions(): array
    {
        $options = [];
        if(isset($this->limit)) $options['limit'] = $this->limit;
        if(isset($this->offset)) $options['offset'] = $this->offset;
        if(isset($this->order))
            $options['order'] = $this->orderProp . ' ' . $this->order;
        return $options;
    }

}
