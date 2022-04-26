<?php namespace Leven\ORM;

use Leven\DBA\Common\AdapterInterface;
use Leven\DBA\Common\Exception\AdapterException;
use Leven\ORM\{Attributes\PropConfig, Converters\BaseConverter};
use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException, RepositoryDatabaseException};

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
     * @throws RepositoryDatabaseException
     */
    public function get(string $entityClass, string $primaryValue): Entity
    {
        $entityConfig = $this->config->for($entityClass);

        if (isset($this->cache[$entityClass][$primaryValue]))
            return $this->cache[$entityClass][$primaryValue];

        try {
            $rows = $this->db->select($entityConfig->table)
                ->columns($entityConfig->propsColumn)
                ->where($entityConfig->getPrimaryColumn(), $primaryValue)
                ->limit(1)
                ->execute()->rows;
        } catch (AdapterException $e){
            throw new RepositoryDatabaseException(previous: $e);
        }

        $row = $rows[0] ?? throw new EntityNotFoundException;
        $props = $this->parsePropsFromDbRow($entityClass, $row);
        return $this->spawnEntityFromProps($entityClass, $props);
    }


    protected function parsePropsFromDbRow(string $entityClass, array $row): array
    {
        $entityConfig = $this->config->for($entityClass);

        $decoded = json_decode($row[$entityConfig->propsColumn]);

        $props = [];
        foreach ($decoded as $column => $value) {
            $propName = $entityConfig->columns[$column] ?? null;
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


    protected function spawnEntityFromProps(string $entityClass, array $props): Entity
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

    public function spawnEntityFromDbRow(string $entityClass, array $row): Entity
    {
        $props = $this->parsePropsFromDbRow($entityClass, $row);
        return $this->spawnEntityFromProps($entityClass, $props);
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

            // TODO update props value only if it's "dirty"

            // TODO do this better
            try { $row = $this->generateDbRow($entity); }
            catch(\Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            try {
                $this->db->update($entityConfig->table)
                    ->set($row)
                    ->where($entityConfig->getPrimaryColumn(), $entity->$primaryProp)
                    ->limit(1)
                    ->execute();
            } catch (AdapterException $e) {
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

            // TODO do this better
            try { $row = $this->generateDbRow($entity, true); }
            catch(\Exception $e) {
                if(count($entities) > 1) $this->txnRollback();
                throw $e;
            }

            try {
                $this->db->insert($entityConfig->table, $row);
            } catch (AdapterException $e) {
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
    protected function generateDbRow(Entity $entity, $isCreation = false): array
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

    public function find(string $entityClass, array $conditions = []): Query
    {
        return new Query(
            repo: $this,
            class: $entityClass,
            conditions: $conditions
        );
    }

    public function all(string $entityClass): Query
    {
        return $this->find($entityClass);
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
        try { $this->db->txnBegin(); }
        catch (AdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    /**
     * @throws RepositoryDatabaseException
     */
    public function txnCommit()
    {
        try { $this->db->txnCommit(); }
        catch (AdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    /**
     * @throws RepositoryDatabaseException
     */
    public function txnRollback()
    {
        try { $this->db->txnRollback(); }
        catch (AdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }

}
