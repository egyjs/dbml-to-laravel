<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\AlterMigrationGenerator;
use Egyjs\DbmlToLaravel\Generation\ColumnDefinitionBuilder;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnPair;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnReference;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\IndexDefinition;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ReferenceTable;
use Egyjs\DbmlToLaravel\Parsing\Dbml\TableDiff;

it('generates add column in up and dropColumn in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        addedColumns: [new Column('phone', new ColumnType('varchar'), false, false, false, false)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("Schema::table('users'");
    expect($content)->toContain("\$table->string('phone', 255)");
    expect($content)->toContain("\$table->dropColumn('phone')");
});

it('generates dropColumn in up and recreate in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        droppedColumns: [new Column('phone', new ColumnType('varchar'), false, false, false, false)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("\$table->dropColumn('phone')");
    // The recreated column should appear in the down() section
    expect($content)->toContain("\$table->string('phone', 255)");
});

it('generates change column with ->change()', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $old = new Column('name', new ColumnType('varchar'), false, false, true, false);
    $new = new Column('name', new ColumnType('varchar'), false, false, false, false);
    $diff = new TableDiff(
        tableName: 'users',
        modifiedColumns: [new ColumnPair($old, $new)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain('->change()');
    // The new column is nullable (notNull=false), so it should have ->nullable()
    expect($content)->toContain('->nullable()');
});

it('correctly replaces stub placeholders', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'orders',
        addedColumns: [new Column('status', new ColumnType('varchar'), false, false, true, false)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("Schema::table('orders'");
    expect($content)->not->toContain('{{ tableName }}');
    expect($content)->not->toContain('{{ upColumns }}');
    expect($content)->not->toContain('{{ downColumns }}');
});

it('generates add index in up and drop index in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        addedIndexes: [new IndexDefinition('idx_email', ['email'], true)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("\$table->unique(['email'], 'idx_email')");
    expect($content)->toContain("\$table->dropIndex('idx_email')");
});

it('generates drop index in up and recreate in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(
        tableName: 'users',
        droppedIndexes: [new IndexDefinition('idx_email', ['email'], true)]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("\$table->dropIndex('idx_email')");
    expect($content)->toContain("\$table->unique(['email'], 'idx_email')");
});

it('generates add foreign key in up and drop foreign key in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $ref = new ColumnReference(new ReferenceTable('posts'), 'id');
    $diff = new TableDiff(
        tableName: 'comments',
        addedForeignKeys: [
            ['column' => 'post_id', 'reference' => $ref]
        ]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("\$table->foreign('post_id')");
    expect($content)->toContain("->references('id')");
    expect($content)->toContain("->on('posts')");
    expect($content)->toContain("\$table->dropForeign(['post_id'])");
});

it('generates drop foreign key in up and add foreign key in down', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $ref = new ColumnReference(new ReferenceTable('posts'), 'id');
    $diff = new TableDiff(
        tableName: 'comments',
        droppedForeignKeys: [
            ['column' => 'post_id', 'reference' => $ref]
        ]
    );

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("\$table->dropForeign(['post_id'])");
    expect($content)->toContain("\$table->foreign('post_id')");
    expect($content)->toContain("->on('posts')");
});

it('generates empty alter migration when diff is empty', function () {
    $generator = new AlterMigrationGenerator(new ColumnDefinitionBuilder([]));
    $diff = new TableDiff(tableName: 'users');

    $stub = file_get_contents(__DIR__ . '/../../stubs/alter-migration.stub');
    $content = $generator->generate($diff, $stub);

    expect($content)->toContain("Schema::table('users'");
    expect($content)->not->toContain('$table->');
});