<?php namespace Leven\ORM;

use Leven\ORM\Exceptions\{EntityNotFoundException, PropertyValidationException, RepositoryDatabaseException};
use Leven\DBA\Common\DatabaseAdapterInterface;
use Leven\DBA\Common\Exception\{DatabaseAdapterException, EmptyResultException};
use DateTime;
use Exception;

// TODO:
// saving multiple entities (and use transactions)

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

            if (isset($propConfig->parent))
                $props[$propName] = $this->get($propConfig->parent, $value);
            else if (isset($propConfig->loadCaster))
                $props[$propName] = $this->{$propConfig->loadCaster}($value, ...$propConfig->castParams);

            else if ($propConfig->serialize){    if(!empty($value)) $props[$propName] = unserialize($value); }
            else if ($propConfig->jsonize){      if(!empty($value)) $props[$propName] = json_decode($value); }
            else                                $props[$propName] = $value;

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
    public function update(Entity $entity): static
    {
        $class = get_class($entity);
        $entityConfig = $this->config->for($class);
        $primaryProp = $entityConfig->primaryProp;

        // TODO update props value only if some of the non-indexed props are dirty

        $row = $this->generateDbRow($entity);

        try {
            $this->db->update(
                table: $entityConfig->table,
                data: $row,
                conditions: [
                    $entityConfig->getPrimaryColumn() => $entity->$primaryProp
                ],
                options: ['limit' => 1]
            );
        }
        catch(Exception $e){
            throw new RepositoryDatabaseException(previous: $e);
        }

        return $this;
    }

    /**
     * @throws PropertyValidationException
     * @throws RepositoryDatabaseException
     */
    public function store(Entity $entity): static
    {
        $class = get_class($entity);
        $entityConfig = $this->config->for($class);
        $primaryProp = $entityConfig->primaryProp;

        $row = $this->generateDbRow($entity, true);

        try { $this->db->insert($entityConfig->table, $row); }
        catch(Exception $e){ throw new RepositoryDatabaseException(previous: $e); }

        $this->cache[$class][$entity->$primaryProp] = $entity;

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

        $row = [];
        $row[$propsColumn] = [];

        foreach ($entityConfig->getProps() as $prop => $propConfig) {
            if($isCreation && $propConfig->createMethod)
                $entity->$prop = $this->{$propConfig->createMethod}($class);

            if($propConfig->updateMethod)
                $entity->$prop = $this->{$propConfig->updateMethod}($class);

            if (!isset($entity->$prop)) continue; // TODO check if this is good
            $validator = new Validator($propConfig->validation); // TODO rethink what exactly should be validated
            //$validator($entity->$prop);

            if (isset($propConfig->parent))
                $value = $entity->$prop->{$this->config->for($propConfig->parent)->primaryProp};

            else if (isset($propConfig->saveCaster))
                $value = $this->{$propConfig->saveCaster}($entity->$prop, ...$propConfig->castParams);

            else if ($propConfig->serialize)    $value = serialize($entity->$prop);
            else if ($propConfig->jsonize)      $value = json_encode($entity->$prop);
            else                                $value = $entity->$prop;

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



    public function txnBegin()
    {
        try {
            return $this->db->txnBegin();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    public function txnCommit()
    {
        try {
            $this->db->txnCommit();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }


    public function txnRollback()
    {
        try {
            $this->db->txnRollback();
        } catch (DatabaseAdapterException $e) {
            throw new RepositoryDatabaseException(previous: $e);
        }
    }

    private function dateTimeNow(string $entityClass): DateTime
    {
        return new DateTime();
    }

    private function castDateTimeToTimestamp(DateTime $input): int
    {
        return $input->getTimestamp();
    }

    private function castTimestampToDateTime(int $input): DateTime
    {
        return DateTime::createFromFormat('U', $input);
    }

    private function castCollectionToPrimariesString(Collection $collection, string $entityClass): string
    {
        $primaryProp = $this->config->for($entityClass)->primaryProp;
        return implode(',', $collection->arrayOfProps($primaryProp));

    }

    private function castPrimariesStringToCollection(string $primaries, string $entityClass): Collection
    {
        $collection = new Collection($entityClass);

        $primaries = !empty($primaries) ? explode(',', $primaries) : [];
        foreach ($primaries as $primary)
            $collection->add($this->get($entityClass, $primary));

        return $collection;
    }

}
