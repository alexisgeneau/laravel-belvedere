<?php

declare(strict_types=1);

namespace Belvedere\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use RuntimeException;

final readonly class Assets
{
    public function __construct(private string $directory) {}

    public function css(?string $nonce = null): Htmlable
    {
        $attribute = $this->nonceAttribute($nonce);

        return new HtmlString(
            '<style data-scheme="light"'.$attribute.'>'.$this->read('styles.css').'</style>'
            .'<style data-scheme="dark"'.$attribute.'>'.$this->read('styles-dark.css').'</style>'
            .'<style'.$attribute.'>'.$this->read('app.css').'</style>'
        );
    }

    /** @param array<string, mixed> $settings */
    public function js(array $settings, ?string $nonce = null): Htmlable
    {
        return new HtmlString(
            '<script type="module"'.$this->nonceAttribute($nonce).'>'
            .'window.Belvedere = '.Js::from($settings)->toHtml().';'
            .$this->read('app.js')
            .'</script>'
        );
    }

    private function read(string $file): string
    {
        $contents = @file_get_contents($this->directory.'/'.$file);

        if ($contents === false) {
            throw new RuntimeException("Unable to load the Belvedere asset [{$file}]. Run `npm run build` if you installed the package from source.");
        }

        return $contents;
    }

    private function nonceAttribute(?string $nonce): string
    {
        return $nonce === null || $nonce === '' ? '' : ' nonce="'.e($nonce).'"';
    }
}
