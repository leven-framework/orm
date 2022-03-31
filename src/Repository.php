<?php namespace Leven\ORM;

use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException, RepositoryDatabaseException};
use Leven\ORM\{Attributes\PropConfig, Converters\BaseConverter};
use Leven\DBA\Common\DatabaseAdapterInterface;
use Leven\DBA\Common\Exception\{DatabaseAdapterException, EmptyResultException};
use Exception;
use Throwable;

abstract class Repository
{

    private array $cache;

    public function __construct(
        private readonly DatabaseAdapterInterface $db,
        private readonly RepositoryConfig $config = new RepositoryConfig
    )
    {
    }

    public function getDb(): DatabaseAdapterInterface
    {
        return $this->db;
    }

    public function getConfig(): RepositoryConfig
    {
        return $this->config;
    }

    /**
     * @throws EntityNotFoundException
     * @throws RepositoryDatabaseException
     */
    public function get(string $entityClass, string $primaryValue): Entity
    {
        $entityConfig = $this->config->for($entityClass);

        if (isset($this->cache[$entityClass][$primaryValue]))
            return $this->cache[$entityClass][$primaryValue];

        try {
            $row = $this->db->get(
                table: $entityConfig->table,
                columns: $entityConfig->propsColumn,
                conditions: [
                    $entityConfig->getPrimaryColumn() => $primaryValue
                ],
                options: [ 'single' => 'true' ]
            )->row;
        }
        catch (EmptyResultException){
            throw new EntityNotFoundException;
        } catch (Exception $e){
            throw new RepositoryDatabaseException(previous: $e);
        }

        $props = $this->parsePropsFromDbRow($entityClass, $row);
        return $this->spawnEntityFromProps($entityClass, $props);
    }


    public function parsePropsFromDbRow(string $entityClass, array $row): array
    {
        $entityConfig = $this->config->for($entityClass);

        $decoded = json_decode($row[$entityConfig->propsColumn]);

        $props = [];
        foreach ($decoded as $column => $value) {
            $propName = $entityConfig->columns[$column];
            $propConfig = $entityConfig->getProp($propName);

            if (!empty($propConfig->parent)) {
                $props[$propName] = $this->get($propConfig->typeClass, $value);
            }
            else if (isset($propConfig->converter)) {
                /** @var BaseConverter $converter */
                $converter = new $propConfig->converter($this, $entityClass, $propName);
                $props[$propName] = $converter->convertForPhp($value);
            }
            else $props[$propName] = $value;

        }

        return $props;
    }


    public function spawnEntityFromProps(string $entityClass, array $props): Entity
    {
        $entityConfig = $this->config->for($entityClass);
        $primaryValue = $props[$entityConfig->primaryProp];

        if (isset($this->cache[$entityClass][$primaryValue]))
            return $this->cache[$entityClass][$primaryValue];

        $constructorProps = [];
        foreach ($entityConfig->constructorProps as $propName) {
            // if for some reason the prop isn't in database, assume it's nullable
            $constructorProps[$propName] = $props[$propName] ?? null;
            unset($props[$propName]);
        }

        $entity = new $entityClass(...$constructorProps);
        foreach ($props as $prop => $value)
            $entity->$prop = $value;

        $this->cache[$entityClass][$primaryValue] = $entity;

        return $entity;
    }

    /**
     * @throws PropertyValidationException
     * @throws RepositoryDatabaseException
     */
    public function update(Entity ...$entities): static
    {
        if(count($entities) > 1) $this->txnBegin();

        foreach($entities as $entity) {
            $class = get_class($entity);
            $entityConfig = $this->config->for($class);
            $primaryProp = $entityConfig->primaryProp;

            // TODO update props value only if some of the non-indexed props are dirty

            try { $row = $this->generateDbRow($entity); }
            catch(Throwable $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            try {
                $this->db->update(
                    table: $entityConfig->table,
                    data: $row,
                    conditions: [
                        $entityConfig->getPrimaryColumn() => $entity->$primaryProp
                    ],
                    options: ['limit' => 1]
                );
            } catch (Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw new RepositoryDatabaseException(previous: $e);
            }
        }

        if(count($entities) > 1) $this->txnCommit();
        return $this;
    }

    /**
     * @throws PropertyValidationException
     * @throws RepositoryDatabaseException
     */
    public function store(Entity ...$entities): static
    {
        if(count($entities) > 1) $this->txnBegin();

        foreach($entities as $entity) {
            $class = get_class($entity);
            $entityConfig = $this->config->for($class);
            $primaryProp = $entityConfig->primaryProp;

            try { $row = $this->generateDbRow($entity, true); }
            catch(Throwable $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            try {
                $this->db->insert($entityConfig->table, $row);
            } catch (Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw new RepositoryDatabaseException(previous: $e);
            }

            $this->cache[$class][$entity->$primaryProp] = $entity;
        }

        if(count($entities) > 1) $this->txnCommit();
        return $this;
    }

    /**
     * @throws PropertyValidationException
     */
    private function generateDbRow(Entity $entity, $isCreation = false): array
    {
        $class = get_class($entity);
        $entityConfig = $this->config->for($class);
        $propsColumn = $entityConfig->propsColumn;

        $entity->onUpdate();
        $isCreation && $entity->onCreate();

        $row = [];
        $row[$propsColumn] = [];

        foreach ($entityConfig->getProps() as $prop => $propConfig) {
            /** @var PropConfig $propConfig */

            // property's either not initialized or null, we're not going to store it
            if (!isset($entity->$prop)) continue;

            if (!empty($propConfig->parent)) {
                $parentPrimaryProp = $this->config->for($propConfig->typeClass)->primaryProp;
                $value = $entity->$prop->$parentPrimaryProp;
            }
            else if (isset($propConfig->converter)) {
                /** @var BaseConverter $converter */
                $converter = new $propConfig->converter($this, $class, $prop);
                $value = $converter->convertForDatabase($entity->$prop);
            }
            else $value = $entity->$prop;

            $validator = new Validator($propConfig->validation);
            $validator->validate($value, $prop);

            $row[$propsColumn][$propConfig->column] = $value;
            if ($propConfig->index) $row[$propConfig->column] = $value;
        }

        $row[$propsColumn] = json_encode($row[$propsColumn]);
        return $row;
    }


    public function delete(string $entityClass, string $primaryValue): static
    {
        $entityConfig = $this->config->for($entityClass);

        foreach($this->config->getStore() as $childEntityClass => $childEntityConfig)
            if(isset($childEntityConfig->parentColumns[$entityClass])) {
                $childPrimaryColumn = $childEntityConfig->getPrimaryColumn();

                $result = $this->db->get(
                    table: $childEntityConfig->table,
                    columns: $childPrimaryColumn,
                    conditions: [
                        $childEntityConfig->parentColumns[$entityClass] => $primaryValue
                    ]
                );

                foreach($result->rows as $row){
                    $childEntityPrimaryValue = $row[$childPrimaryColumn];
                    $this->delete($childEntityClass, $childEntityPrimaryValue);
                }
            }

        $result = $this->db->delete(
            table: $entityConfig->table,
            conditions: [
                $entityConfig->getPrimaryColumn() => $primaryValue
            ],
            options: ['single' => true]
        );

        if(!$result->count) throw new EntityNotFoundException;

        unset($this->cache[$entityClass][$primaryValue]);

        return $this;
    }

    public function deleteEntity(Entity $entity): static
    {
        $entityClass = get_class($entity);
        $primaryValue = $entity->{$this->config->for($entityClass)->primaryProp};
        $this->delete($entityClass, $primaryValue);
        unset($entity);

        return $this;
    }


    public function all(string $entityClass): Query
    {
        return new Query(
            repo: $this,
            class: $entityClass,
            conditions: []
        );
    }

    public function find(string $entityClass, array $conditions = []): Query
    {
        return new Query(
            repo: $this,
            class: $entityClass,
            conditions: $conditions
        );
    }

    // TODO accept array of entities as first param
    public function findChildrenOf(Entity|array $parentEntity, string $childrenEntityClass, array $conditions = []): Query
    {
        $parentEntityClass = get_class($parentEntity);
        $parentClassConfig = $this->config->for($parentEntityClass);
        $childClassConfig = $this->config->for($childrenEntityClass);

        return new Query(
            repo: $this,
            class: $childrenEntityClass,
            conditions: [
                $childClassConfig->parentColumns[$parentEntityClass] =>
                    $parentEntity->{$parentClassConfig->primaryProp},
                ...$conditions
            ]
        );
    }


    /**
     * @throws RepositoryDatabaseException
     */
    public function txnBegin()
    {
        try {
            return $this->db->txnBegin();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    /**
     * @throws RepositoryDatabaseException
     */
    public function txnCommit()
    {
        try {
            $this->db->txnCommit();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    /**
     * @throws RepositoryDatabaseException
     */
    public function txnRollback()
    {
        try {
            $this->db->txnRollback();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }

}
