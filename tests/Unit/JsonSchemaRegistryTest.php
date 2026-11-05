<?php

namespace Wexample\PhpJsonSchema\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\PhpJsonSchema\Class\JsonSchemaRegistry;
use Wexample\PhpJsonSchema\Exception\CircularSchemaException;
use Wexample\PhpJsonSchema\Exception\UnknownSchemaException;

/**
 * A schema resolved whole: what it refers to in place, by name or by file,
 * its parents folded in, its own words winning; a circle and an unknown
 * name refused.
 */
class JsonSchemaRegistryTest extends TestCase
{
    public function testAChildHoldsWhatItsParentSays(): void
    {
        $registry = (new JsonSchemaRegistry())
            ->add('core/text', ['title' => 'Text', 'type' => 'string', 'maxLength' => 100])
            ->add('core/markdown', ['title' => 'Markdown', 'allOf' => [['$ref' => 'core/text']], 'contentMediaType' => 'text/markdown', 'maxLength' => 50]);

        $markdown = $registry->resolve('core/markdown');

        $this->assertSame('Markdown', $markdown->title);
        $this->assertSame('string', $markdown->type);
        $this->assertSame('text/markdown', $markdown->contentMediaType);
        $this->assertSame(50, $markdown->maxLength);
        $this->assertObjectNotHasProperty('allOf', $markdown);
        $this->assertSame([], $registry->validate('core/markdown', '# Title'));
        $this->assertNotSame([], $registry->validate('core/markdown', 12));
    }

    public function testAnObjectInheritsPropertiesAndRefusesTheRest(): void
    {
        $registry = (new JsonSchemaRegistry())
            ->add('field_base', ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']])
            ->add('text_input', ['allOf' => [['$ref' => 'field_base.schema.json']], 'properties' => ['type' => ['const' => 'text']], 'required' => ['type'], 'unevaluatedProperties' => false]);

        $field = $registry->resolve('text_input');

        $this->assertSame(['type', 'name'], array_keys((array) $field->properties));
        $this->assertSame(['type', 'name'], $field->required);
        $this->assertFalse($field->additionalProperties);
        $this->assertSame([], $registry->validate('text_input', ['type' => 'text', 'name' => 'city']));
        $this->assertCount(1, $registry->validate('text_input', ['type' => 'text']));
        $this->assertNotSame([], $registry->validate('text_input', ['type' => 'text', 'name' => 'city', 'color' => 'red']));
    }

    public function testACircleIsRefused(): void
    {
        $registry = (new JsonSchemaRegistry())
            ->add('a', ['allOf' => [['$ref' => 'b']]])
            ->add('b', ['allOf' => [['$ref' => 'a']]]);

        $this->expectException(CircularSchemaException::class);
        $registry->resolve('a');
    }

    public function testAnUnknownReferenceIsRefused(): void
    {
        $registry = (new JsonSchemaRegistry())->add('a', ['allOf' => [['$ref' => 'nowhere.json']]]);

        $this->expectException(UnknownSchemaException::class);
        $registry->resolve('a');
    }

    public function testAReferenceInsideADocumentIsLeftToTheValidator(): void
    {
        $registry = (new JsonSchemaRegistry())->add('a', ['$defs' => ['n' => ['type' => 'integer']], '$ref' => '#/$defs/n']);

        $this->assertSame('#/$defs/n', $registry->resolve('a')->{'$ref'});
    }

    public function testADirectoryIsReadByFileName(): void
    {
        $directory = sys_get_temp_dir().'/json-schema-'.uniqid();
        mkdir($directory);
        file_put_contents($directory.'/field_base.schema.json', '{"$id": "field_base.schema.json", "type": "object", "properties": {"name": {"type": "string"}}}');
        file_put_contents($directory.'/text_input.schema.json', '{"$id": "text_input.schema.json", "allOf": [{"$ref": "field_base.schema.json"}]}');

        $registry = (new JsonSchemaRegistry())->addDirectory($directory);

        $this->assertSame(['field_base', 'text_input'], $registry->getNames());
        $this->assertObjectNotHasProperty('$id', $registry->resolve('text_input'));
        $this->assertSame('object', $registry->resolve('text_input')->type);

        array_map(unlink(...), glob($directory.'/*'));
        rmdir($directory);
    }
}
