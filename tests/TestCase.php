<?php

declare(strict_types=1);

namespace QDenka\QueryDoctor\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use QDenka\QueryDoctor\Providers\QueryDoctorServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [QueryDoctorServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('query-doctor.enabled', true);
        $app['config']->set('query-doctor.allowed_environments', ['testing']);
        $app['config']->set('query-doctor.storage.path', ':memory:');
    }
}
