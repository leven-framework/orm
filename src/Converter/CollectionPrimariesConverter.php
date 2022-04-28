<?php namespace Leven\ORM\Converter;

use Leven\ORM\Collection;

class CollectionPrimariesConverter extends BaseConverter
{

    /**
     * it's possible to extend this converter and
     * define the entity class name, so it won't be kept in database value
     *
     * @var string|null
     */
    protected ?string $collectionEntityClass = null;

    public function convertForDatabase($value): string
    {
        /** @var Collection $value */

        $entityClass = $this->collectionEntityClass ?? $value->getClass();

        $primaryProp = $this->repo->getEntityConfig($entityClass)->primaryProp;
        $collectionPrimaries = implode(';', $value->arrayOfProps($primaryProp));

        // if the class is not extended, we need to store the
        // collection entity class name in database, so we can later reconstruct the collection
        empty($this->collectionEntityClass) and
            $collectionPrimaries = "$entityClass:$collectionPrimaries";

        return $collectionPrimaries;
    }

    public function convertForPhp($value): Collection
    {
        /** @var string $value */

        $entityClass = $this->collectionEntityClass;

        // if the class is not extended, we need to read the collection entity name
        empty($entityClass) and [$entityClass, $value] = explode(':', $value, 2);
        empty($value) and $value = [];

        $collection = new Collection($entityClass);
        foreach (explode(';', $value) as $primary)
            $collection->add( $this->repo->get($entityClass, $primary) );

        return $collection;
    }

}