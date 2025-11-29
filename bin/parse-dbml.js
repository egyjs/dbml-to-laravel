#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');
const { Parser } = require('@dbml/core');

const [, , inputPath] = process.argv;

function exitWithError(message) {
  console.error(message);
  process.exit(1);
}

if (!inputPath) {
  exitWithError('Usage: parse-dbml <path-to-dbml-file>');
}

const resolvedPath = path.resolve(process.cwd(), inputPath);

if (!fs.existsSync(resolvedPath)) {
  exitWithError(`DBML file not found: ${resolvedPath}`);
}

const readFile = (file) => fs.readFileSync(file, 'utf8');

function normalizeType(type) {
  if (!type || typeof type !== 'object') {
    return { name: String(type || ''), schemaName: null, args: [] };
  }

  return {
    name: type.type_name || type.typeName || '',
    schemaName: type.schemaName || null,
    args: type.args || [],
    original: type.originalTypeName || null,
  };
}

function normalizeDefault(dbDefault) {
  if (!dbDefault) {
    return null;
  }

  if (typeof dbDefault === 'object') {
    return {
      value: dbDefault.value ?? null,
      type: dbDefault.type ?? null,
    };
  }

  return {
    value: dbDefault,
    type: typeof dbDefault,
  };
}

function serializeDatabase(database) {
  const result = {
    tables: [],
    enums: [],
    refs: [],
  };

  database.schemas.forEach((schema) => {
    schema.tables.forEach((table) => {
      result.tables.push({
        name: table.name,
        schema: schema.name,
        note: table.note || null,
        columns: table.fields.map((field) => ({
          name: field.name,
          type: normalizeType(field.type),
          primaryKey: Boolean(field.pk),
          unique: Boolean(field.unique),
          notNull: Boolean(field.not_null),
          autoIncrement: Boolean(field.increment),
          note: field.note || null,
          defaultValue: normalizeDefault(field.dbdefault),
        })),
        indexes: (table.indexes || []).map((index) => ({
          name: index.name || null,
          type: index.type || null,
          unique: Boolean(index.type === 'unique' || index.unique),
          columns: (index.columns || []).map((column) => column.value || column.name || column),
        })),
      });
    });

    schema.enums.forEach((_enum) => {
      result.enums.push({
        name: _enum.name,
        schema: schema.name,
        values: _enum.values.map((value) => value.name),
      });
    });

    schema.refs.forEach((ref) => {
      result.refs.push({
        name: ref.name || null,
        schema: schema.name,
        color: ref.color || null,
        onDelete: ref.onDelete || null,
        onUpdate: ref.onUpdate || null,
        endpoints: ref.endpoints.map((endpoint) => ({
          schema: endpoint.schemaName || schema.name,
          table: endpoint.tableName,
          columns: endpoint.fieldNames,
          relation: endpoint.relation,
        })),
      });
    });
  });

  return result;
}

try {
  const parser = new Parser();
  const dbml = readFile(resolvedPath);
  const database = parser.parse(dbml, 'dbml');
  const payload = serializeDatabase(database);
  process.stdout.write(JSON.stringify(payload));
} catch (error) {
  const detailedMessage = error?.message
    || error?.errors?.[0]?.message
    || error?.toString()
    || 'Unknown parser error';
  exitWithError(`Failed to parse DBML: ${detailedMessage}`);
}
