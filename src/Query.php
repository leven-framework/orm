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
        protected RepositoryInterface $repo,
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

    public function count(): int
    {
        return $this->getFromRepoDB(true)->rows[0]['COUNT(*)'];
    }

    public function get(): Collection
    {
        foreach ($this->getFromRepoDB()->rows as $row)
            $entities[] = $this->repo->spawnEntityFromDbRow($this->class, $row);

        return new Collection($this->class, ...($entities??[]));
    }

    public function getFirst(): Entity
    {
        $rows = $this->getFromRepoDB()->rows;
        if(!isset($rows[0])) throw new EntityNotFoundException;

        return $this->repo->spawnEntityFromDbRow($this->class, $rows[0]);
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

    private function getFromRepoDB(bool $count = false): DatabaseAdapterResponse
    {
        try {
            return $this->repo->getDb()->get(
                table: $this->getEntityConfig()->table,
                columns: !$count ? $this->getEntityConfig()->propsColumn : 'COUNT(*)',
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
