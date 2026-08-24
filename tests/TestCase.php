<?php

declare(strict_types=1);

namespace FlexPDF\Tests;

use FlexPDF\FlexPdfServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app)
    {
        return [
            FlexPdfServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('view.paths', [__DIR__ . '/fixtures/views']);
    }
}
