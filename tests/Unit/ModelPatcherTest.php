<?php

declare(strict_types=1);

use Egyjs\DbmlToLaravel\Generation\ModelContentBuilder;
use Egyjs\DbmlToLaravel\Generation\ModelPatcher;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Column;
use Egyjs\DbmlToLaravel\Parsing\Dbml\ColumnType;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Schema;
use Egyjs\DbmlToLaravel\Parsing\Dbml\Table;

it('replaces content within existing markers', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // @dbml-sync:fillable
    protected $fillable = [
        'name',
    ];
    // @enddbml-sync:fillable

    // @dbml-sync:casts
    protected $casts = [];
    // @enddbml-sync:casts

    public function customMethod()
    {
        return true;
    }
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
        new Column('email', new ColumnType('varchar'), false, true, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain("'name'");
    expect($result)->toContain("'email'");
    expect($result)->toContain('// @dbml-sync:fillable');
    expect($result)->toContain('// @enddbml-sync:fillable');
    expect($result)->toContain('customMethod');
});

it('inserts markers into unmarked model', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'name',
    ];

    public function customMethod()
    {
        return true;
    }
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain('// @dbml-sync:fillable');
    expect($result)->toContain('// @enddbml-sync:fillable');
    expect($result)->toContain('customMethod');
});

it('preserves content outside markers', function () {
    $modelContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use SomeCustomTrait;

    // @dbml-sync:fillable
    protected $fillable = [
        'name',
    ];
    // @enddbml-sync:fillable

    public function isAdmin(): bool
    {
        return true;
    }
}
PHP;

    $patcher = new ModelPatcher(new ModelContentBuilder);
    $table = new Table('users', 'public', [
        new Column('id', new ColumnType('int'), true, false, false, true),
        new Column('name', new ColumnType('varchar'), false, false, false, false),
    ]);
    $schema = new Schema([$table], []);

    $result = $patcher->patch($modelContent, $table, 'User', $schema);

    expect($result)->toContain('use SomeCustomTrait');
    expect($result)->toContain('isAdmin');
});