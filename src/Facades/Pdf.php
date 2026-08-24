<?php

declare(strict_types=1);

namespace FlexPDF\Facades;

use FlexPDF\PdfBuilder;
use FlexPDF\PdfFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PdfBuilder html(string $html)
 * @method static PdfBuilder loadFile(string $path)
 * @method static PdfBuilder view(string|View $view, array $data = [])
 * @method static PdfBuilder loadView(string|View $view, array $data = [])
 * @method static PdfFactory withConfig(array $config)
 *
 * @see PdfFactory
 */
class Pdf extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PdfFactory::class;
    }
}
