Read this when you need to edit the Node parser or understand how the DBML-to-JSON conversion works.

## Two Files, One Job

| File | What it is | When to edit |
|------|-----------|-------------|
| `bin/parse-dbml.js` | Source script — uses `@dbml/core` | Changing parser logic, output shape, or error handling |
| `bin/parse-dbml.runtime.cjs` | esbuild bundle (committed artifact) | Never edit directly — regenerate with `npm run build-parser` |

Consumers never need `npm install`. The `.runtime.cjs` bundle ships in the package so `node` is the only runtime requirement.

## Build Workflow

```bash
npm install                    # install @dbml/core + esbuild (dev only)
npm run build-parser           # esbuild → bin/parse-dbml.runtime.cjs
```

The `build-parser` script in `package.json`:

```
esbuild bin/parse-dbml.js --bundle --minify --platform=node --target=node18 --outfile=bin/parse-dbml.runtime.cjs
```

After editing `bin/parse-dbml.js`, always run `npm run build-parser` and commit **both** files in the same PR. A stale `.runtime.cjs` causes parser/runtime mismatches.

## Resolution Order

`NodeDbmlParser::resolveParserScript()` (in `src/Parsing/NodeDbmlParser.php`) looks for the parser script in this order:

1. `bin/parse-dbml.runtime.cjs` — the compiled bundle (preferred)
2. `bin/parse-dbml.js` — fallback when bundle missing
3. Throws `RuntimeException` if neither found

The constructor also accepts an optional `$parserScript` argument for testing or custom paths.

## JSON Payload Shape

The Node script emits JSON on stdout. Structure:

```json
{
  "tables": [
    {
      "name": "users",
      "schema": "public",
      "note": null,
      "columns": [
        {
          "name": "id",
          "type": { "name": "int", "schemaName": null, "args": [], "original": null },
          "primaryKey": true,
          "unique": false,
          "notNull": false,
          "autoIncrement": false,
          "note": null,
          "defaultValue": null
        }
      ],
      "indexes": [
        {
          "name": "idx_email",
          "type": "unique",
          "unique": true,
          "columns": ["email"]
        }
      ]
    }
  ],
  "enums": [
    {
      "name": "user_role",
      "schema": "public",
      "values": ["admin", "editor"]
    }
  ],
  "refs": [
    {
      "name": null,
      "schema": "public",
      "color": null,
      "onDelete": "cascade",
      "onUpdate": null,
      "endpoints": [
        { "schema": "public", "table": "posts", "columns": ["user_id"], "relation": "*" },
        { "schema": "public", "table": "users", "columns": ["id"], "relation": "1" }
      ]
    }
  ]
}
```

Key normalization in `bin/parse-dbml.js`:

- `type` objects are normalized via `normalizeType()` — extracts `type_name`/`typeName`, `schemaName`, `args`
- `defaultValue` objects are normalized via `normalizeDefault()` — extracts `value` and `type`
- Ref endpoints carry `relation` field (`'*'` or `'1'`) — PHP's `SchemaFactory::determineDirection()` reads this
- Index columns are normalized to string arrays via `normalizeIndexColumns()`

## Error Handling

- Node script exits with code 1 on error, writes message to stderr
- `NodeDbmlParser.php` catches `ProcessFailedException`, throws `RuntimeException` with stderr content
- If Node stdout is not valid JSON, `json_decode` throws `JsonException` → wrapped in `RuntimeException`
- Process timeout: 30 seconds (`$process->setTimeout(30)`)

## Runtime Requirements

Node.js 18+ must be on PATH. `NodeDbmlParser` runs:

```php
new Process(['node', $this->parserScript, $path]);
```

No `npm` or `npx` at runtime — just `node` executing the self-contained bundle.