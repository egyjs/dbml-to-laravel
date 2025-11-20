<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

it('generates models and migrations using the Node.js DBML parser', function () {
    $filesystem = new Filesystem();
    $tempPath = sys_get_temp_dir() . '/dbml-to-laravel-' . uniqid();

    try {
        $filesystem->makeDirectory($tempPath, 0755, true, true);
        $filesystem->makeDirectory("{$tempPath}/app/Models", 0755, true, true);
        $filesystem->makeDirectory("{$tempPath}/database/migrations", 0755, true, true);
        $filesystem->makeDirectory("{$tempPath}/storage", 0755, true, true);

        $this->app->setBasePath($tempPath);
        $this->app->useAppPath("{$tempPath}/app");
        $this->app->useDatabasePath("{$tempPath}/database");
        $this->app->useStoragePath("{$tempPath}/storage");

        $dbml = <<<'DBML'
        Table users {
          id int [pk]
          name varchar [not null]
          status user_status
        }

        Table posts {
          id int [pk]
          user_id int [ref: > users.id]
          title text [not null]
          published bool
        }

        Enum user_status {
          active
          inactive
        }
        DBML;

        $dbmlPath = $tempPath . '/schema.dbml';
        file_put_contents($dbmlPath, $dbml);

        $exitCode = Artisan::call('generate:dbml', ['file' => $dbmlPath, '--force' => true]);

        expect($exitCode)->toBe(0);

        $userModel = $tempPath . '/app/Models/User.php';
        $postModel = $tempPath . '/app/Models/Post.php';

        expect($filesystem->exists($userModel))->toBeTrue();
        expect($filesystem->exists($postModel))->toBeTrue();

        $userModelContent = $filesystem->get($userModel);
        expect($userModelContent)->toContain("class User");
        expect($userModelContent)->toContain("'name'");
        expect($userModelContent)->toContain("'status'");

        $postModelContent = $filesystem->get($postModel);
        expect($postModelContent)->toContain("function user()");
        expect($postModelContent)->toContain("belongsTo(User::class, 'user_id')");
        expect($postModelContent)->toContain("'published' => 'boolean'");

        $userMigration = collect(glob($tempPath . '/database/migrations/*create_users_table.php'))->first();
        $postMigration = collect(glob($tempPath . '/database/migrations/*create_posts_table.php'))->first();

        expect($userMigration)->not->toBeNull();
        expect($postMigration)->not->toBeNull();

        $userMigrationContent = $filesystem->get($userMigration);
        expect($userMigrationContent)->toContain("\$table->string('name')");
        expect($userMigrationContent)->toContain("\$table->enum('status', ['active', 'inactive'])");

        $postMigrationContent = $filesystem->get($postMigration);
        expect($postMigrationContent)->toContain("\$table->foreignId('user_id')->constrained('users')");
        expect($postMigrationContent)->toContain("\$table->boolean('published')->nullable()");
        expect($postMigrationContent)->toContain("\$table->text('title')");
    } finally {
        $filesystem->deleteDirectory($tempPath);
    }
});
