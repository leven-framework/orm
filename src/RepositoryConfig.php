<?php namespace Leven\ORM;

use Leven\ORM\Attributes\{EntityConfig, PropConfig, ValidationConfig};
use ReflectionClass, ReflectionException, ReflectionProperty, ReflectionNamedType;
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


    public function for(?string $entityClass): EntityConfig
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

        // store all properties promoted in constructor
        foreach ($classReflection->getConstructor()?->getParameters() ?? [] as $constructorParam)
            if($constructorParam->isPromoted()) $promotedParams[] = $constructorParam->name;

        $attribute = $classReflection->getAttributes(EntityConfig::class)[0] ?? null;
        $entityConfig = $attribute?->getArguments()[0] ?? new EntityConfig();

        $entityConfig->name = $entityClass;
        empty($entityConfig->table) && $entityConfig->generateTable($classReflection->getShortName());

        foreach ($classReflection->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $attribute = $prop->getAttributes(PropConfig::class)[0] ?? null;
            $propConfig = $attribute?->newInstance() ?? new PropConfig();

            $propConfig->name = $prop->name;
            empty($propConfig->column) && $propConfig->generateColumn($prop->name);

            $validationAttribute = $prop->getAttributes(ValidationConfig::class)[0] ?? null;
            $propConfig->validation = $validationAttribute?->newInstance() ?? new ValidationConfig();

            in_array($prop->name, $promotedParams ?? []) && $propConfig->inConstructor = true;

            $propType = $prop->getType();
            if($propType instanceof ReflectionNamedType && !$propType->isBuiltin()) {
                $propConfig->typeClass = $propType->getName();

                if(is_subclass_of($prop->$propConfig, Entity::class))
                    $propConfig->parent = $propConfig->index = true;
            }

            $entityConfig->addProp($propConfig);
        }

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