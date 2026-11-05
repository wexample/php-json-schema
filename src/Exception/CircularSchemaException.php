<?php

namespace Wexample\PhpJsonSchema\Exception;

use LogicException;

/** Schemas referring to one another in a circle: none of them resolves. */
class CircularSchemaException extends LogicException
{
    /** @param string[] $names the schemas met, the first again at the end */
    public static function forPath(array $names): self
    {
        return new self(sprintf('Schemas referring to one another in a circle: %s.', implode(' → ', $names)));
    }
}
