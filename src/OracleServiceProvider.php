<?php

namespace Amohamed\DatabaseAi\Oracle;

use Illuminate\Support\Facades\DB;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class OracleServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('database-ai')
            ->hasConfigFile()
            ->hasViews();
    }

    public function registeringPackage()
    {
        DB::macro('ask', function (string $question) {
            return $this->app->make(Oracle::class)->ask($question);
        });
        DB::macro('askForQuery', function (string $question) {
            return $this->app->make(Oracle::class)->getQuery($question);
        });
    }
}