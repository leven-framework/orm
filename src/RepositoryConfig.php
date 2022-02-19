<?php namespace Leven\ORM;

use Leven\ORM\Attributes\{EntityConfig, PropConfig, ValidationConfig};
use ReflectionClass, ReflectionException, ReflectionProperty;
use Exception;

class RepositoryConfig
{

    private array $store;


    /**
     * @return array
     */
    public function getStore(): array
    {
        return $this->store;
    }


    public function for(string $entityClass): EntityConfig
    {
        if(!isset($this->store[$entityClass]))
            throw new Exception("entity class $entityClass not recognized");

        return $this->store[$entityClass];
    }

    /**
     * @throws ReflectionException
     */
    public function scanEntityClass(string $entityClass): void
    {
        $classReflection = new ReflectionClass($entityClass);

        $attribute = $classReflection->getAttributes(EntityConfig::class)[0] ?? null;
        if (!is_null($attribute)) $entityConfig = $attribute->newInstance();
        else $entityConfig = new EntityConfig();

        $entityConfig->name = $entityClass;
        if (!isset($entityConfig->table))
            $entityConfig->setTableFromClassName($classReflection->getShortName());

        foreach ($classReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attribute = $prop->getAttributes(PropConfig::class)[0] ?? null;
            if (!is_null($attribute)) $propConfig = $attribute->newInstance();
            else $propConfig = new PropConfig();

            $propConfig->name = $prop->name;
            if (!isset($propConfig->column))
                $propConfig->setColumnFromPropName($prop->name);

            $validationAttribute = $prop->getAttributes(ValidationConfig::class)[0] ?? null;
            if (!is_null($validationAttribute))
                $propConfig->validation = $validationAttribute->newInstance();
            else
                $propConfig->validation = new ValidationConfig();

            $entityConfig->addProp($propConfig);

            $propType = $prop->getType()->getName();
            if(is_subclass_of($propType, Entity::class)) {
                $propConfig->parent = $propType;
                $propConfig->index = true;
                $entityConfig->parentColumns[$propType] = $propConfig->column;
            }
        }

        foreach ($classReflection->getConstructor()?->getParameters() ?? [] as $param)
            $entityConfig->constructorProps[] = $param->name;

        $this->store[$entityClass] = $entityConfig;
    }

    /**
     * @throws ReflectionException
     */
    public function scanEntityClasses(string ...$entityClasses): void
    {
        foreach ($entityClasses as $entityClass)
            $this->scanEntityClass($entityClass);
    }

}