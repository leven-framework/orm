<?php namespace Leven\ORM\Converters;

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

        $primaryProp = $this->repo->getConfig()->for($entityClass)->primaryProp;
        $collectionPrimaries = implode(';', $value->arrayOfProps($primaryProp));

        // if the class is not extended, we need to store the
        // collection entity class name in database, so we can later reconstruct the collection
        if($this->collectionEntityClass === null)
            $collectionPrimaries = "$entityClass:$collectionPrimaries";

        return $collectionPrimaries;
    }

    public function convertForPhp($value): Collection
    {
        /** @var string $value */

        $entityClass = $this->collectionEntityClass;

        // if the class is not extended, we need to find the collection entity name
        if($entityClass === null){
            $value = explode(':', $value, 2);
            $entityClass = $value[0];
            $value = $value[1]; // leave the rest as is
        }

        $collection = new Collection($entityClass);

        $primaries = empty($value) ? [] : explode(';', $value);
        foreach ($primaries as $primary)
            $collection->add($this->repo->get($entityClass, $primary));

        return $collection;
    }

}