<?php

declare(strict_types=1);

namespace FlexPDF;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FlexPdfServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('flexpdf')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PdfFactory::class, function ($app): PdfFactory {
            return new PdfFactory(
                config('flexpdf', []),
                $app->bound(ViewFactory::class) ? $app->make(ViewFactory::class) : null,
            );
        });

        $this->app->alias(PdfFactory::class, 'flexpdf');
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [PdfFactory::class, 'flexpdf'];
    }
}
