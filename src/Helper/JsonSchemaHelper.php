<?php

namespace Wexample\PhpJsonSchema\Helper;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * What is done to one schema, whole: its inheritance folded into it, data
 * checked against it. Schemas are objects, as JSON decodes them — an empty
 * `{}` stays an object, which an array would not.
 */
final class JsonSchemaHelper
{
    // What a schema says of itself, not of the data: not inherited.
    private const array ANNOTATIONS = ['$id', '$schema', '$comment', 'title', 'description', 'examples'];

    /**
     * An object's `allOf` folded into it, as deep as the schema goes: what
     * each entry says of the data the object keeps unless it says it itself
     * — its own properties win over its parents' —, required ones joined;
     * and what an object leaves unevaluated
     * refused as what is additional — the one reading of
     * `unevaluatedProperties` every validator agrees on.
     */
    public static function fold(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(self::fold(...), $node);
        }

        if (! is_object($node)) {
            return $node;
        }

        foreach (get_object_vars($node) as $key => $value) {
            $node->{$key} = self::fold($value);
        }

        if (isset($node->allOf) && is_array($node->allOf)) {
            $entries = $node->allOf;
            unset($node->allOf);
            $properties = (array) ($node->properties ?? []);
            $required = is_array($node->required ?? null) ? $node->required : [];

            foreach ($entries as $entry) {
                if (! is_object($entry)) {
                    continue;
                }

                foreach (get_object_vars($entry) as $key => $value) {
                    if (! in_array($key, self::ANNOTATIONS, true) && ! property_exists($node, $key)) {
                        $node->{$key} = $value;
                    }
                }

                $properties += (array) ($entry->properties ?? []);
                $required = array_merge($required, is_array($entry->required ?? null) ? $entry->required : []);
            }

            if ($properties !== []) {
                $node->properties = (object) $properties;
            }
            if ($required !== []) {
                $node->required = array_values(array_unique($required));
            }
        }

        if (property_exists($node, 'unevaluatedProperties')) {
            $node->additionalProperties = $node->unevaluatedProperties;
            unset($node->unevaluatedProperties);
        }

        return $node;
    }

    /**
     * What is wrong with data against a schema, one line a fault —
     * `fields.0.name: The data must be a string` —; none when it follows it.
     *
     * @return string[]
     */
    public static function validate(object $schema, mixed $data): array
    {
        $result = (new Validator())->validate(self::toJsonValue($data), $schema);

        if ($result->isValid()) {
            return [];
        }

        return array_map(self::formatError(...), self::leafErrors($result->error()));
    }

    /** A schema held as an array, as an object again — an empty one is `{}`, which accepts anything. */
    public static function toObject(array|object $schema): object
    {
        return json_decode(json_encode($schema, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT * (int) ($schema === [])), false, 512, JSON_THROW_ON_ERROR);
    }

    /** A schema as an array, for a store that keeps arrays. */
    public static function toArray(object $schema): array
    {
        return json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    // Arrays with keys are objects, as JSON reads them.
    private static function toJsonValue(mixed $data): mixed
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return ValidationError[] the faults themselves, not the keywords grouping them */
    private static function leafErrors(ValidationError $error): array
    {
        if ($error->subErrors() === []) {
            return [$error];
        }

        return array_merge(...array_map(self::leafErrors(...), $error->subErrors()));
    }

    private static function formatError(ValidationError $error): string
    {
        $message = $error->message();

        foreach ($error->args() as $key => $value) {
            $message = str_replace('{'.$key.'}', is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value, $message);
        }

        $path = $error->data()->fullPath();

        return $path === [] ? $message : implode('.', $path).': '.$message;
    }
}
