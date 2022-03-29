<?php namespace Leven\ORM;

use DateTime;

trait RepositoryGeneratorsTrait {

    private function dateTimeNow(string $entityClass): DateTime
    {
        return new DateTime();
    }

}