<?php

namespace Wexample\PhpJsonSchema\Class;

use Wexample\PhpJsonSchema\Exception\CircularSchemaException;
use Wexample\PhpJsonSchema\Exception\UnknownSchemaException;
use Wexample\PhpJsonSchema\Helper\JsonSchemaHelper;

/**
 * Schemas held by name — the files of a directory, rows of a store —, each
 * resolved into one document a validator or a model reads alone: every
 * `$ref` to another schema of the registry put in place, every `allOf`
 * folded (JsonSchemaHelper::fold). A `$ref` names a schema as the registry
 * does (`core/text`), or as its file is named (`field_base.schema.json`).
 * A reference inside a document (`#/$defs/…`) is left to the validator.
 */
class JsonSchemaRegistry
{
    /** @var array<string, object> */
    private array $schemas = [];

    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * @param string[] $extensions what a file of a schema is named with, after its name
     */
    public function __construct(
        private readonly array $extensions = ['.schema.json', '.json'],
    ) {
    }

    public function add(string $name, array|object $schema): static
    {
        $this->schemas[$name] = JsonSchemaHelper::toObject($schema);
        // What resolved through it may resolve otherwise now.
        $this->resolved = [];

        return $this;
    }

    /** Every file of the directory named with the first extension, by its name. */
    public function addDirectory(string $directory): static
    {
        $extension = $this->extensions[0];

        foreach (glob(rtrim($directory, '/').'/*'.$extension) as $file) {
            $this->add(basename($file, $extension), json_decode(file_get_contents($file), false, 512, JSON_THROW_ON_ERROR));
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    /** @return string[] */
    public function getNames(): array
    {
        return array_keys($this->schemas);
    }

    /** The schema as it was added. */
    public function get(string $name): object
    {
        return clone ($this->schemas[$name] ?? throw UnknownSchemaException::forName($name));
    }

    /** The schema whole: what it refers to in place, its inheritance folded. */
    public function resolve(string $name): object
    {
        if (! isset($this->resolved[$name])) {
            $schema = JsonSchemaHelper::fold($this->inline($this->get($name), [$name]));
            // Inlined whole, the document needs no id of its own, and a
            // validator wants an absolute one or none.
            unset($schema->{'$id'});
            $this->resolved[$name] = $schema;
        }

        return unserialize(serialize($this->resolved[$name]));
    }

    /** What is wrong with data against a schema of the registry; none when it follows it. */
    public function validate(string $name, mixed $data): array
    {
        return JsonSchemaHelper::validate($this->resolve($name), $data);
    }

    /** The name of the schema a reference points to; null for one inside a document. */
    public function getReferencedName(string $reference): ?string
    {
        $target = explode('#', $reference, 2)[0];

        if ($target === '') {
            return null;
        }

        if ($this->has($target)) {
            return $target;
        }

        foreach ($this->extensions as $extension) {
            if (str_ends_with($target, $extension) && $this->has($name = substr($target, 0, -strlen($extension)))) {
                return $name;
            }
        }

        throw UnknownSchemaException::forName($target);
    }

    /** @param string[] $path the schemas being inlined, outermost first */
    private function inline(mixed $node, array $path): mixed
    {
        if (is_array($node)) {
            return array_map(fn (mixed $value): mixed => $this->inline($value, $path), $node);
        }

        if (! is_object($node)) {
            return $node;
        }

        if (is_string($node->{'$ref'} ?? null) && null !== ($name = $this->getReferencedName($node->{'$ref'}))) {
            if (in_array($name, $path, true)) {
                throw CircularSchemaException::forPath([...$path, $name]);
            }

            $target = $this->inline($this->get($name), [...$path, $name]);
            unset($target->{'$id'}, $target->{'$schema'});

            return $target;
        }

        foreach (get_object_vars($node) as $key => $value) {
            $node->{$key} = $this->inline($value, $path);
        }

        return $node;
    }
}
