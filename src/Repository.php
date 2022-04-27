<?php

namespace Leven\ORM;

use InvalidArgumentException;
use Leven\DBA\Common\AdapterInterface;
use Leven\DBA\Common\BuilderPart\WhereGroup;
use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException};

abstract class Repository implements RepositoryInterface
{

    protected array $cache;

    public function __construct(
        protected readonly AdapterInterface $db,
        protected readonly RepositoryConfig $config = new RepositoryConfig
    )
    {
    }

    public function getDb(): AdapterInterface
    {
        return $this->db;
    }

    public function getConfig(): RepositoryConfig
    {
        return $this->config;
    }


    /**
     * @throws EntityNotFoundException
     */
    public function get(string $entityClass, string $primaryValue): Entity
    {
        return $this->try($entityClass, $primaryValue)
            ?? throw new EntityNotFoundException;
    }

    public function try(string $entityClass, string $primaryValue): ?Entity
    {
        if (isset($this->cache[$entityClass][$primaryValue]))
            return $this->cache[$entityClass][$primaryValue];

        $entityConfig = $this->config->for($entityClass);

        $rows = $this->db->select($entityConfig->table)
            ->columns($entityConfig->propsColumn)
            ->where($entityConfig->getPrimaryColumn(), $primaryValue)
            ->limit(1)
            ->execute()->rows;

        if(!isset($rows[0])) return null;
        $props = $this->parsePropsFromDbRow($entityClass, $rows[0]);
        return $this->spawnEntityFromProps($entityClass, $props);
    }


    protected function parsePropsFromDbRow(string $entityClass, array $row): array
    {
        $entityConfig = $this->config->for($entityClass);

        $decoded = json_decode($row[$entityConfig->propsColumn]);
        foreach ($decoded as $column => $value) {
            $propName = $entityConfig->columns[$column] ?? null;
            $propConfig = $entityConfig->getProp($propName);

            $props[$propName] = match(true){
                default => $value,
                $propConfig->parent => $this->get($propConfig->typeClass, $value),
                isset($propConfig->converter) => (new $propConfig->converter($this, $entityClass, $propName))
                                                    ->convertForPhp($value),
            };
        }

        return $props ?? [];
    }


    protected function spawnEntityFromProps(string $entityClass, array $props): Entity
    {
        $entityConfig = $this->config->for($entityClass);
        $primaryValue = $props[$entityConfig->primaryProp];

        if (isset($this->cache[$entityClass][$primaryValue]))
            return $this->cache[$entityClass][$primaryValue];

        foreach ($entityConfig->constructorProps as $propName) {
            // if for whatever reason constructor prop isn't in database, assume it's nullable
            $constructorProps[$propName] = $props[$propName] ?? null;
            unset($props[$propName]);
        }

        $entity = new $entityClass( ...($constructorProps ?? []) );
        foreach ($props as $prop => $value)
            $entity->$prop = $value;

        $this->cache[$entityClass][$primaryValue] = $entity;

        return $entity;
    }

    public function spawnEntityFromDbRow(string $entityClass, array $row): Entity
    {
        $props = $this->parsePropsFromDbRow($entityClass, $row);
        return $this->spawnEntityFromProps($entityClass, $props);
    }

    /**
     * @throws PropertyValidationException
     */
    public function update(Entity ...$entities): static
    {
        if(count($entities) > 1) $this->txnBegin();

        foreach($entities as $entity) {
            $class = get_class($entity);
            $entityConfig = $this->config->for($class);
            $primaryProp = $entityConfig->primaryProp;

            // TODO update props value only if it's "dirty"

            try {
                $this->db->update($entityConfig->table)
                    ->set( $this->generateDbRow($entity) )
                    ->where($entityConfig->getPrimaryColumn(), $entity->$primaryProp)
                    ->limit(1)
                    ->execute();
            }
            catch (\Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }
        }

        if(count($entities) > 1) $this->txnCommit();
        return $this;
    }

    /**
     * @throws PropertyValidationException
     */
    public function store(Entity ...$entities): static
    {
        if(count($entities) > 1) $this->txnBegin();

        foreach($entities as $entity) {
            $class = get_class($entity);
            $entityConfig = $this->config->for($class);
            $primaryProp = $entityConfig->primaryProp;

            try {
                $row = $this->generateDbRow($entity, true);
                $this->db->insert($entityConfig->table, $row);
            }
            catch (\Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            $this->cache[$class][$entity->$primaryProp] = $entity;
        }

        if(count($entities) > 1) $this->txnCommit();
        return $this;
    }

    /**
     * @throws PropertyValidationException
     */
    protected function generateDbRow(Entity $entity, $isCreation = false): array
    {
        $class = get_class($entity);
        $entityConfig = $this->config->for($class);
        $propsColumn = $entityConfig->propsColumn;

        $entity->onUpdate();
        $isCreation && $entity->onCreate();

        foreach ($entityConfig->getProps() as $prop => $propConfig) {
            // property's either not initialized or null, we're not going to store it
            if (!isset($entity->$prop)) continue;

            $value = match(true){
                default => $entity->$prop,

                $propConfig->parent =>
                    $entity->$prop->{$this->config->for($propConfig->typeClass)->primaryProp},

                isset($propConfig->converter) =>
                    (new $propConfig->converter($this, $class, $prop))->convertForDatabase($entity->$prop),
            };

            $validator = new Validator($propConfig->validation);
            $validator->validate($value, $prop);

            $row[$propsColumn][$propConfig->column] = $value;
            $propConfig->index && $row[$propConfig->column] = $value;
        }

        $row[$propsColumn] = json_encode($row[$propsColumn] ?? []);
        return $row;
    }


    /**
     * @throws EntityNotFoundException
     */
    public function delete(string $entityClass, string $primaryValue): static
    {
        $entityConfig = $this->config->for($entityClass);

        foreach($this->config->getStore() as $childEntityClass => $childEntityConfig)
            if(isset($childEntityConfig->parentColumns[$entityClass])) {
                $childPrimaryColumn = $childEntityConfig->getPrimaryColumn();

                $result = $this->db->select($childEntityConfig->table)
                    ->columns($childPrimaryColumn)
                    ->where($childEntityConfig->parentColumns[$entityClass], $primaryValue)
                    ->execute();

                foreach($result->rows as $row){
                    $childEntityPrimaryValue = $row[$childPrimaryColumn];
                    $this->delete($childEntityClass, $childEntityPrimaryValue);
                }
            }

        $result = $this->db->delete($entityConfig->table)
            ->where($entityConfig->getPrimaryColumn(), $primaryValue)
            ->limit(1)
            ->execute();

        if(!$result->count) throw new EntityNotFoundException;

        unset($this->cache[$entityClass][$primaryValue]);

        return $this;
    }

    /**
     * @throws EntityNotFoundException
     */
    public function deleteEntity(Entity $entity): static
    {
        $entityClass = get_class($entity);
        $primaryValue = $entity->{$this->config->for($entityClass)->primaryProp};
        $this->delete($entityClass, $primaryValue);
        unset($entity);

        return $this;
    }

    public function find(string $entityClass): Query
    {
        return new Query($this, $entityClass);
    }

    public function all(string $entityClass): Collection
    {
        return $this->find($entityClass)->get();
    }

    public function findChildrenOf(Entity|array $parentEntities, string $childrenEntityClass): Query
    {
        if(!is_array($parentEntities)) $parentEntities = [$parentEntities];
        if(empty($parentEntities)) throw new InvalidArgumentException('parentEntities cannot be empty');

        $conditions = [];
        $childClassConfig = $this->config->for($childrenEntityClass);

        foreach($parentEntities as $parentEntity){
            $parentEntityClass = get_class($parentEntity);
            $parentClassConfig = $this->config->for($parentEntityClass);

            $conditions[$childClassConfig->parentColumns[$parentEntityClass]] =
                $parentEntity->{$parentClassConfig->primaryProp};
        }

        $query = new Query($this, $childrenEntityClass);

        $query->dbQuery->andWhere(function(WhereGroup $w) use ($conditions) {
            foreach($conditions as $column => $value) $w->where($column, $value);
        });

        return $query;
    }


    public function txnBegin()
    {
        $this->db->txnBegin();
    }

    public function txnCommit()
    {
        $this->db->txnCommit();
    }

    public function txnRollback()
    {
        $this->db->txnRollback();
    }

}
