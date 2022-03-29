<?php namespace Leven\ORM;

use DateTime;

trait RepositoryCastersTrait {

    private function castDateTimeToTimestamp(DateTime $input): int
    {
        return $input->format('c');
    }

    private function castTimestampToDateTime(string $input): DateTime
    {
        return new DateTime($input);
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