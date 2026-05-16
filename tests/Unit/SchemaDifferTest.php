<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\SchemaDiffer;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('detects added tables', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
        new Table('posts', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->addedTables)->toHaveCount(1);
    expect($diff->addedTables[0]->getName())->toBe('posts');
    expect($diff->droppedTables)->toHaveCount(0);
    expect($diff->modifiedTables)->toHaveCount(0);
});

it('detects dropped tables', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
        new Table('posts', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->droppedTables)->toHaveCount(1);
    expect($diff->droppedTables[0])->toBe('posts');
    expect($diff->addedTables)->toHaveCount(0);
});

it('detects added columns', function () {
    $old = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [
            new Column('id', new ColumnType('int'), true, false, false, true),
            new Column('email', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->addedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->addedColumns[0]->getName())->toBe('email');
});

it('detects dropped columns', function () {
    $old = new Schema([
        new Table('users', 'public', [
            new Column('id', new ColumnType('int'), true, false, false, true),
            new Column('email', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->droppedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->droppedColumns[0]->getName())->toBe('email');
});

it('detects modified columns', function () {
    $old = new Schema([
        new Table('users', 'public', [
            new Column('name', new ColumnType('varchar'), false, false, false, false),
        ]),
    ], []);

    $new = new Schema([
        new Table('users', 'public', [
            new Column('name', new ColumnType('varchar'), false, false, true, false),
        ]),
    ], []);

    $diff = SchemaDiffer::diff($old, $new);

    expect($diff->modifiedTables)->toHaveCount(1);
    expect($diff->modifiedTables[0]->modifiedColumns)->toHaveCount(1);
    expect($diff->modifiedTables[0]->modifiedColumns[0]->old->isNotNull())->toBeFalse();
    expect($diff->modifiedTables[0]->modifiedColumns[0]->new->isNotNull())->toBeTrue();
});

it('returns empty diff for identical schemas', function () {
    $schema = new Schema([
        new Table('users', 'public', [new Column('id', new ColumnType('int'), true, false, false, true)]),
    ], []);

    $diff = SchemaDiffer::diff($schema, $schema);

    expect($diff->isEmpty())->toBeTrue();
});