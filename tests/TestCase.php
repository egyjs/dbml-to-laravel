<?php

namespace Egyjs\DbmlToLaravel\Tests;

use Egyjs\DbmlToLaravel\DbmlToLaravelServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * Holds the latest HTTP/test response so Testbench can reset it between tests.
     */
    protected static ?TestResponse $latestResponse = null;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Egyjs\\DbmlToLaravel\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            DbmlToLaravelServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_dbml-to-laravel_table.php.stub';
        $migration->up();
        */
    }
}
