<?php

namespace Egyjs\DbmlToLaravel;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Egyjs\DbmlToLaravel\Commands\GenerateFromDbml;

class DbmlToLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('dbml-to-laravel')
            ->hasCommand(GenerateFromDbml::class);
    }
}
