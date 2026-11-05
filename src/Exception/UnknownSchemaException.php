<?php

namespace Wexample\PhpJsonSchema\Exception;

use InvalidArgumentException;

/** A schema asked for, or referred to, that the registry does not hold. */
class UnknownSchemaException extends InvalidArgumentException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('No schema "%s" in the registry.', $name));
    }
}
