<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // Links are built from APP_URL and Laravel derives the test request from it at
        // boot, so pin it before bootstrapping: tests must not depend on the developer's .env.
        putenv('APP_URL=http://localhost');
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = 'http://localhost';

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
