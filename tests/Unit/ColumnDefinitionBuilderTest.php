<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnDefaultValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\EnumValue;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ReferenceTable;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;

it('builds a string column definition', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('name', new ColumnType('varchar'), false, false, false, false);
    $result = $builder->buildColumnDefinition($column);
    expect($result)->toContain("\$table->string('name', 255)");
    expect($result)->toEndWith(';');
});

it('builds an auto-incrementing primary key', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('id', new ColumnType('int'), true, false, false, true);
    $result = $builder->buildColumnDefinition($column);
    expect($result)->toContain("\$table->increments('id')");
});

it('builds a nullable column', function () {
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('email', new ColumnType('varchar'), false, false, false, false);
    $result = $builder->buildColumnDefinition($column);
    expect($result)->toContain('->nullable()');
});

it('builds an enum column using registered enums', function () {
    $enum = new EnumDefinition('user_role', [
        new EnumValue('admin'),
        new EnumValue('editor'),
    ]);
    $builder = new ColumnDefinitionBuilder(['user_role' => $enum]);
    $column = new Column('role', new ColumnType('user_role'), false, false, false, false);
    $result = $builder->buildColumnDefinition($column);
    expect($result)->toContain("\$table->enum('role', ['admin', 'editor'])");
});

it('builds a foreignId column for bigint unsigned FK', function () {
    $refTable = new ReferenceTable('users');
    $ref = new ColumnReference($refTable, 'id');
    $builder = new ColumnDefinitionBuilder([]);
    $column = new Column('user_id', new ColumnType('bigint unsigned'), false, false, false, false, null, [$ref]);
    $result = $builder->buildColumnDefinition($column);
    expect($result)->toContain("\$table->foreignId('user_id')");
    expect($result)->toContain("->constrained('users')");
});