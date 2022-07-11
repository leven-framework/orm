<?php

namespace Leven\ORM;

use Leven\DBA\Common\AdapterResponse;
use Throwable, DomainException;
use InvalidArgumentException;
use Leven\DBA\Common\AdapterInterface;
use Leven\DBA\Common\BuilderPart\WhereGroup;
use Leven\ORM\Exception\{EntityNotFoundException, PropertyValidationException};
use Leven\ORM\Attribute\EntityConfig;

class Repository implements RepositoryInterface
{

    /** @var EntityConfig[] $config */
    protected array $config;

    protected array $cache;

    public function __construct(
        protected readonly AdapterInterface $db,
    )
    {
    }

    public function getDb(): AdapterInterface
    {
        return $this->db;
    }

    public function getEntityConfig(?string $entityClass): EntityConfig
    {
        return $this->config[$entityClass] ??
            throw new DomainException("entity class $entityClass not configured");
    }

    public function addEntityConfig(EntityConfig $config): void
    {
        // TODO check if same table name has been added
        $this->config[$config->class] = $config;
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

        $entityConfig = $this->getEntityConfig($entityClass);
        $primaryColumn = $entityConfig->getPrimaryColumn();

        $result = $this->db->select($entityConfig->table)
            ->columns($primaryColumn, $entityConfig->propsColumn)
            ->where($primaryColumn, $primaryValue)
            ->limit(1)
            ->execute();

        return $this->generateEntities($entityConfig, $result)[0] ?? null;
    }


    /**
     * @throws PropertyValidationException
     */
    public function update(Entity ...$entities): static
    {
        if(count($entities) > 1) $this->txnBegin();

        foreach($entities as $entity) {
            $class = get_class($entity);
            $entityConfig = $this->getEntityConfig($class);
            $primaryProp = $entityConfig->primaryProp;

            // TODO update props value only if it's "dirty"

            try {
                $this->db->update($entityConfig->table)
                    ->set( $this->generateRow($entity) )
                    ->where($entityConfig->getPrimaryColumn(), $entity->$primaryProp)
                    ->limit(1)
                    ->execute();
            }
            catch (Throwable $e) {
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
            $entityConfig = $this->getEntityConfig($class);
            $primaryProp = $entityConfig->primaryProp;

            try {
                $row = $this->generateRow($entity, true);
                $result = $this->db->insert($entityConfig->table, $row);
            }
            catch (Throwable $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            if(!isset($entity->$primaryProp) && $result->lastId !== null) {
                $entity->$primaryProp = $result->lastId;
                $this->update($entity);
            }

            $this->cache[$class][$entity->$primaryProp] = $entity;
        }

        if(count($entities) > 1) $this->txnCommit();
        return $this;
    }


    /**
     * @throws EntityNotFoundException
     */
    public function delete(string $entityClass, string $primaryValue): static
    {
        $entityConfig = $this->getEntityConfig($entityClass);

        foreach($this->config as $childEntityClass => $childEntityConfig)
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
        $primaryValue = $entity->{$this->getEntityConfig($entityClass)->primaryProp};
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
        $childClassConfig = $this->getEntityConfig($childrenEntityClass);

        foreach($parentEntities as $parentEntity){
            $parentEntityClass = get_class($parentEntity);
            $parentClassConfig = $this->getEntityConfig($parentEntityClass);

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



    // INTERNAL METHODS

    public function generateEntities(EntityConfig $config, AdapterResponse $result): array
    {
        if (!$result->count) return [];

        $entities = []; // because `foreach ($entities ?? [] as &$entity)` doesn't work as expected
        foreach ($result->rows as $row){
            $props = json_decode($row[$config->propsColumn]);

            foreach ($config->getProps() as $prop => $propConfig)
                $entity[$prop] = match (true) {
                    !isset($props->$prop) => null, // TODO implement default value
                    isset($propConfig->converter) =>
                        (new $propConfig->converter($this, $config->class, $prop))->convertForPhp($props->$prop),
                    $props->$prop === null => null,
                    $propConfig->parent => ($result->count == 1) ?
                        $this->get($propConfig->typeClass, $props->$prop) : // if single entity, fetch parents directly
                        $parents[$propConfig->typeClass][] = $props->$prop, // or fetch all parents together later
                    default => $props->$prop,
                };

            // set primary after because it would be overwritten by the above loop
            $entity[$config->primaryProp] = $row[$config->getPrimaryColumn()];
            $entities[] = $entity;
        }

        if (empty($parents)) return array_map( fn($e) => $this->constructFromProps($config, $e), $entities);

        foreach ($parents as $class => $primaries) (new Query($this, $class))
            ->where($this->getEntityConfig($class)->primaryProp, 'IN', array_unique($primaries))->get();

        foreach ($config->getProps() as $propConfig)
            if ($propConfig->parent) foreach ($entities as &$entity)
                $entity[$propConfig->name] = $this->get($propConfig->typeClass, $entity[$propConfig->name]);

        return array_map( fn($e) => $this->constructFromProps($config, $e), $entities );
    }

    protected function constructFromProps(EntityConfig $config, array $props): Entity
    {
        $primary = $props[$config->primaryProp];

        if (isset($this->cache[$config->class][$primary]))
            return $this->cache[$config->class][$primary];

        foreach ($config->constructorProps as $propName) {
            // if for whatever reason constructor prop isn't in database, assume it's nullable
            $constructorProps[$propName] = $props[$propName] ?? null;
            unset($props[$propName]);
        }

        $entity = new $config->class( ...($constructorProps ?? []) );
        foreach ($props as $prop => $value) $entity->$prop = $value;

        return $this->cache[$config->class][$primary] = $entity;
    }

    /**
     * @throws PropertyValidationException
     */
    protected function generateRow(Entity $entity, $isCreation = false): array
    {
        $class = get_class($entity);
        $entityConfig = $this->getEntityConfig($class);
        $propsColumn = $entityConfig->propsColumn;

        $entity->onUpdate();
        $isCreation && $entity->onCreate();

        foreach ($entityConfig->getProps() as $prop => $propConfig) {
            $value = match(true){
                isset($propConfig->converter) =>
                    (new $propConfig->converter($this, $class, $prop))->convertForDatabase($entity->$prop),
                !isset($entity->$prop) => null,
                $propConfig->parent =>
                    $entity->$prop->{$this->getEntityConfig($propConfig->typeClass)->primaryProp},
                default => $entity->$prop,
            };

            $validator = new Validator($propConfig->validation);
            $validator->validate($value, $prop);

            $propConfig->primary || $row[$propsColumn][$propConfig->column] = $value;
            $propConfig->index && $row[$propConfig->column] = $value;
        }

        $row[$propsColumn] = json_encode($row[$propsColumn] ?? []);
        return $row;
    }

}
