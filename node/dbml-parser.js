#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const { Parser, ModelExporter } = require('@dbml/core');

const filePath = process.argv[2];

if (!filePath) {
  console.error('A DBML file path is required.');
  process.exit(1);
}

const resolvedPath = path.resolve(process.cwd(), filePath);

if (!fs.existsSync(resolvedPath)) {
  console.error(`File not found: ${resolvedPath}`);
  process.exit(1);
}

try {
  const dbmlContent = fs.readFileSync(resolvedPath, 'utf-8');
  const parser = new Parser();
  const database = parser.parse(dbmlContent, 'dbml');
  const exported = ModelExporter.export(database, 'json', false);
  const schema = JSON.parse(exported);

  const enums = {};
  (schema.schemas || []).forEach((schemaEntry) => {
    (schemaEntry.enums || []).forEach((enumEntry) => {
      enums[enumEntry.name] = (enumEntry.values || [])
        .map((value) => value.name || value.value)
        .filter(Boolean);
    });
  });

  const references = {};
  (schema.schemas || []).forEach((schemaEntry) => {
    (schemaEntry.refs || []).forEach((ref) => {
      if (!Array.isArray(ref.endpoints) || ref.endpoints.length < 2) {
        return;
      }

      const child = ref.endpoints.find((endpoint) => endpoint.relation === '*') || ref.endpoints[1];
      const parent = ref.endpoints.find((endpoint) => endpoint !== child) || ref.endpoints[0];

      if (!child || !parent) {
        return;
      }

      (child.fieldNames || []).forEach((fieldName) => {
        const key = `${child.tableName}.${fieldName}`;
        references[key] = {
          table: parent.tableName,
          column: (parent.fieldNames && parent.fieldNames[0]) || 'id',
        };
      });
    });
  });

  const tables = [];

  (schema.schemas || []).forEach((schemaEntry) => {
    (schemaEntry.tables || []).forEach((table) => {
      const columns = (table.fields || []).map((field) => {
        const refKey = `${table.name}.${field.name}`;
        const reference = references[refKey];

        const column = {
          name: field.name,
          type: (field.type && field.type.type_name) || 'string',
          primary: Boolean(field.pk),
          nullable: field.not_null !== true,
          notNullable: Boolean(field.not_null),
          references: [],
        };

        if (reference) {
          column.references.push(reference);
        }

        return column;
      });

      tables.push({
        name: table.name,
        columns,
      });
    });
  });

  console.log(
    JSON.stringify(
      {
        tables,
        enums,
      },
      null,
      2,
    ),
  );
} catch (error) {
  console.error(error.message || 'Failed to parse DBML');
  process.exit(1);
}
