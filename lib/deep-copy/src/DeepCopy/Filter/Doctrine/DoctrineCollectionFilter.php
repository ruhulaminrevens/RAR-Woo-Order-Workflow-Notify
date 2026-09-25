<?php

namespace RarWowVendor\DeepCopy\Filter\Doctrine;

use RarWowVendor\DeepCopy\Filter\Filter;
use RarWowVendor\DeepCopy\Reflection\ReflectionHelper;

/**
 * @final
 */
class DoctrineCollectionFilter implements Filter
{
    /**
     * Copies the object property doctrine collection.
     *
     * {@inheritdoc}
     */
    public function apply($object, $property, $objectCopier)
    {
        $reflectionProperty = ReflectionHelper::getProperty($object, $property);

        if (PHP_VERSION_ID < 80100) {
            $reflectionProperty->setAccessible(true);
        }
        $oldCollection = $reflectionProperty->getValue($object);

        $newCollection = $oldCollection->map(
            function ($item) use ($objectCopier) {
                return $objectCopier($item);
            }
        );

        $reflectionProperty->setValue($object, $newCollection);
    }
}
