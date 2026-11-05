# php-json-schema

Version: 1.0.1

Named JSON schemas resolved into one document, and data checked against them.

- `Class\JsonSchemaRegistry` holds schemas by name — added one by one, or the files of a directory (`text_input.schema.json` is `text_input`). `resolve()` puts every `$ref` to another schema of the registry in place (by name, `core/text`, or by file name) and folds every `allOf`: a schema inherits what its parents say of the data, its own words winning. A circle (`CircularSchemaException`) and an unknown name (`UnknownSchemaException`) are refused. `validate()` checks data against a resolved schema.
- `Helper\JsonSchemaHelper` does the same to one schema alone: `fold()`, `validate()` — one line a fault, none when the data follows it.

Validation is opis/json-schema's, draft 2020-12.
