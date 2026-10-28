<?php
#use from https://dev.to/emanuelvintila/creating-an-html-form-for-a-class-part-2-5cih


function MakeObjectFromArray(ReflectionClass $class, array $values)
{
    // we do not call the constructor yet
    $instance = $class->newInstanceWithoutConstructor();
    // first we set each property to their respective value; fields that are
    // no property of the class (e.g. from an older page) are ignored
    foreach ($values as $name => $value) {
        if (!$class->hasProperty($name)) {
            continue;
        }
        $property = $class->getProperty($name);
        if (!$property->isPublic() || $property->isStatic()) {
            continue;
        }
        $property->setValue($instance, $value);
    }
    // note that we have set primitive values to our object properties
    // we late-call the constructor, like PDO does when fetching objects
    // and it re-creates the object instances from the primitive values
    $class->getConstructor()->invoke($instance);

    return $instance;
}
