<?php

declare(strict_types=1);

namespace FlexPDF;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use RuntimeException;

/**
 * Entry point behind the Pdf facade. Each method starts a fresh builder from
 * one of the four supported sources.
 */
class PdfFactory
{
    /** @param array<string, mixed> $config */
    public function __construct(
        protected array $config = [],
        protected ?ViewFactory $views = null,
    ) {}

    public function html(string $html): PdfBuilder
    {
        return new PdfBuilder($html, $this->config);
    }

    public function loadFile(string $path): PdfBuilder
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('No such HTML file: %s', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read HTML file: %s', $path));
        }

        /*
         * Relative <img> and @font-face paths in a standalone file almost
         * always mean "next to the file", which is a more useful default here
         * than the configured base path.
         */
        return $this->html($contents)->basePath(dirname($path));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function view(string|View $view, array $data = []): PdfBuilder
    {
        if ($view instanceof View) {
            return $this->html($view->render());
        }

        if ($this->views === null) {
            throw new RuntimeException(
                'Rendering a view by name needs the Laravel view factory. Pass a View instance instead.',
            );
        }

        return $this->html($this->views->make($view, $data)->render());
    }

    /** @param array<string, mixed> $data */
    public function loadView(string|View $view, array $data = []): PdfBuilder
    {
        return $this->view($view, $data);
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): static
    {
        $clone         = clone $this;
        $clone->config = array_replace_recursive($this->config, $config);

        return $clone;
    }
}
