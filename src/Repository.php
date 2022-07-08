<?php

namespace Leven\ORM;

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

        $rows = $this->db->select($entityConfig->table)
            ->columns($primaryColumn, $entityConfig->propsColumn)
            ->where($primaryColumn, $primaryValue)
            ->limit(1)
            ->execute()->rows;

        if(!isset($rows[0])) return null;
        return $this->spawnEntityFromDbRow($entityClass, $rows[0]);
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
                    ->set( $this->generateDbRow($entity) )
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
                $row = $this->generateDbRow($entity, true);
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

    public function spawnEntityFromDbRow(string $entityClass, array $row): Entity
    {
        $props = $this->parsePropsFromDbRow($entityClass, $row);
        return $this->spawnEntityFromProps($entityClass, $props);
    }

    protected function parsePropsFromDbRow(string $entityClass, array $row): array
    {
        $entityConfig = $this->getEntityConfig($entityClass);

        $props[$entityConfig->primaryProp] = $row[$entityConfig->getPrimaryColumn()];

        foreach (json_decode($row[$entityConfig->propsColumn]) as $column => $value) {
            $propName = $entityConfig->columns[$column] ?? null; // so the next line fails
            $propConfig = $entityConfig->getPropConfig($propName);

            $props[$propName] = match(true){
                isset($propConfig->converter) =>
                    (new $propConfig->converter($this, $entityClass, $propName))->convertForPhp($value),

                // after converter because null may be converted to some other value
                $value === null => null,

                // after null because the entity might not have this parent
                $propConfig->parent => $this->get($propConfig->typeClass, $value),

                default => $value,
            };
        }

        return $props;
    }


    protected function spawnEntityFromProps(string $entityClass, array $props): Entity
    {
        $entityConfig = $this->getEntityConfig($entityClass);
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


    /**
     * @throws PropertyValidationException
     */
    protected function generateDbRow(Entity $entity, $isCreation = false): array
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
