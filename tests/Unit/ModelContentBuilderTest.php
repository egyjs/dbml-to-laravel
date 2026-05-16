<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ReferenceTable;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('generates fillable from columns', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('email', new ColumnType('varchar'), false, true, false, false),
    ];
    $fillable = $builder->generateFillable($columns);
    expect($fillable)->toBe(["'name'", "'email'"]);
});

it('excludes created_at, updated_at, and id from fillable', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('created_at', new ColumnType('datetime'), false, false, false, false),
        new Column('updated_at', new ColumnType('datetime'), false, false, false, false),
        new Column('title', new ColumnType('varchar'), false, false, false, false),
    ];
    $fillable = $builder->generateFillable($columns);
    expect($fillable)->toBe(["'title'"]);
});

it('generates casts filtering out defaults', function () {
    $builder = new ModelContentBuilder;
    $columns = [
        new Column('is_active', new ColumnType('boolean'), false, false, false, false),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('settings', new ColumnType('json'), false, false, false, false),
    ];
    $casts = $builder->generateCasts($columns);
    expect($casts)->toBe(['is_active' => 'boolean', 'settings' => 'array']);
});

it('generates table property when name diverges from convention', function () {
    $builder = new ModelContentBuilder;
    expect($builder->generateTableProperty('users', 'User'))->toBe('');
    expect($builder->generateTableProperty('custom_users', 'User'))->toBe("protected \$table = 'custom_users';");
});

it('generates belongsTo relations from columns with refs', function () {
    $builder = new ModelContentBuilder;
    $refTable = new ReferenceTable('users');
    $ref = new ColumnReference($refTable, 'id');
    $columns = [
        new Column('user_id', new ColumnType('int'), false, false, false, false, null, [$ref]),
    ];
    $relations = $builder->generateBelongsToRelations($columns);
    expect($relations)->toHaveCount(1);
    expect($relations[0])->toBe([
        'type' => 'belongsTo',
        'method' => 'user',
        'relatedTable' => 'User',
        'foreignKey' => 'user_id',
        'ownerKey' => 'id',
    ]);
});