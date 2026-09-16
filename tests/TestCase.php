<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // This runs before RefreshDatabase can migrate or truncate anything.
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$connection.'.database');
        $url = $app['config']->get('database.connections.'.$connection.'.url');
        if (! $app->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:' || $url) {
            throw new \RuntimeException('Tests require testing + sqlite :memory: without DB_URL. Database changes blocked.');
        }

        return $app;
    }
}
