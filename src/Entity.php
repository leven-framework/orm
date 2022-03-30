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

    /**
     * empty but can be extended
     * will be called by repository when the entity gets stored after creation
     * @return void
     */
    public function onCreate(): void
    {
    }

    /**
     * empty but can be extended
     * will be called by repository each time the entity gets updated
     * @return void
     */
    public function onUpdate(): void
    {
    }

}
