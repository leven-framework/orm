<?php namespace Leven\ORM;

use Exception;

abstract class Entity
{

    /**
     * @throws Exception when an undefined property is attempted to be set
     */
    public function __set($name, $value){
        throw new Exception("property $name does not exist");
    }


    /*
    public function children(Repository $repo, array|string $conditions = [], array $additional = [], string $prop = 'parent'): EntitySet|Entity // TODO FIGURE A WAY TO DO THIS
    {
        return $repo->find(
            [$prop => $this->uid] + $conditions,
            $additional
        );
    }

    */
}
