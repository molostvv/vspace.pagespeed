<?php

namespace Vspace\PageSpeed\Tests\Critical;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Critical\CriticalCssProcessor;
use Vspace\PageSpeed\Optimizer;

/**
 * Весь конвейер critical CSS на временном «корне сайта»: файлы CSS, кеш, итоговый HTML.
 */
final class CriticalCssProcessorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', sys_get_temp_dir()) . '/vps-site-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tpl/css', 0777, true);
        file_put_contents($this->root . '/tpl/css/main.css', '.header{height:60px}.hero{background:url(../img/h.jpg)}.footer{color:#333}.btn:hover{color:red}');
        file_put_contents($this->root . '/tpl/css/print.css', '.header{display:none}');
        file_put_contents($this->root . '/tpl/css/fonts.css', '@font-face{font-family:X;src:url(x.woff2)}');
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function html(string $head = ''): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<link href="/tpl/css/main.css?123" type="text/css" rel="stylesheet">'
            . '<link rel="stylesheet" href="/tpl/css/print.css" media="print">'
            . '<link rel="stylesheet" href="https://cdn.example/remote.css">'
            . '<link rel="stylesheet" href="/tpl/css/fonts.css">'
            . $head
            . '</head><body><header class="header"></header><section class="page__content">'
            . '<div class="panel hero"></div><div class="panel second"></div><div class="panel third"></div>'
            . '</section><footer class="footer"></footer></body></html>';
    }

    private function optimize(string $html, array $options = [], bool $preview = false, ?Context &$ctx = null): string
    {
        $config = new Config($options + ['ACTIVE' => 'Y', 'EXCLUDE_FILES' => '/tpl/css/fonts.css', 'ADD_CLASSES' => '', 'FOLD_SKIP_CLASSES' => '']);
        $ctx = new Context($config, $this->root, '/services/seo/', ['example.ru'], $preview);
        return Optimizer::create($config, $this->root)->optimize($html, $ctx);
    }

    public function testInlinesCriticalAndMakesStylesheetsAsync(): void
    {
        $out = $this->optimize($this->html(), ['FOLD_BLOCKS' => 2], false, $ctx);

        $this->assertSame([], $ctx->errors);
        $this->assertSame('built', $ctx->stats['critical']);
        $this->assertMatchesRegularExpression(
            '~<link rel="preload" href="/tpl/css/main.css\?123" as="style"><style data-vps-critical="[0-9a-f]{8}">'
            . '\.header\{height:60px\}\.hero\{background:url\(/tpl/img/h\.jpg\)\}</style>'
            . '<script data-skip-moving="true">\(function\(w,d,n\)\{.*?\}\)\(window,document,1\);</script>'
            . '<link href="/tpl/css/main.css\?123" type="text/css" rel="stylesheet" media="\(min-width: 1024px\)" '
            . 'onload="vpsCss\(this\)" onerror="vpsCss\(this\)" data-vps-async data-vps-media="all">'
            . '<noscript><link href="/tpl/css/main.css\?123" type="text/css" rel="stylesheet"></noscript>~',
            $out
        );
        // print, внешний и исключённый файлы — без изменений
        $this->assertStringContainsString('<link rel="stylesheet" href="/tpl/css/print.css" media="print">', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="https://cdn.example/remote.css">', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="/tpl/css/fonts.css">', $out);
        $this->assertStringNotContainsString('.footer{', $out);

        $this->optimize($this->html(), ['FOLD_BLOCKS' => 2], false, $ctx);
        $this->assertSame('hit', $ctx->stats['critical']);
    }

    public function testExcludedPageKeepsStylesheets(): void
    {
        $html = $this->html();
        $out = $this->optimize($html, ['CRITICAL_EXCLUDE_URLS' => "*-redesign/*\n/services/"], false, $ctx);
        $this->assertSame($html, $out);
        $this->assertSame('excluded', $ctx->stats['critical']);
    }

    public function testPreviewModeRemovesFullStylesheets(): void
    {
        $out = $this->optimize($this->html(), [], true);
        $this->assertStringContainsString('<style data-vps-critical=', $out);
        $this->assertStringNotContainsString('main.css', $out);
        $this->assertStringNotContainsString('vpsCss', $out);
    }

    public function testLargeInlineStyleIsExtractedToFile(): void
    {
        $style = '<style>.hero{color:#fff}.footer{padding:' . str_repeat('1', 50) . 'px}.x{background:url(img/a.png)}</style>';
        $out = $this->optimize($this->html($style), ['INLINE_STYLES_MIN' => 20, 'ASYNC_MODE' => 'print']);

        $this->assertStringNotContainsString('<style>', $out);
        $this->assertStringNotContainsString('rel="preload"', $out);
        $this->assertTrue((bool)preg_match('~<link rel="stylesheet" href="(/bitrix/cache/css/vspace\.pagespeed/[0-9a-f]{2}/[0-9a-f]{32}\.css)" media="\(min-width: 1024px\)"~', $out, $m));
        $saved = file_get_contents($this->root . $m[1]);
        $this->assertStringContainsString('url(/services/seo/img/a.png)', $saved); // url от адреса страницы переписан
        $this->assertStringContainsString('.hero{color:#fff}', $out);
    }

    public function testAsyncMediaBlocksOnlyWideScreens(): void
    {
        $this->assertSame('(min-width: 1024px)', CriticalCssProcessor::asyncMedia(null, 1024));
        $this->assertSame('(min-width: 1024px)', CriticalCssProcessor::asyncMedia('all', 1024));
        $this->assertSame('screen and (min-width: 1200px)', CriticalCssProcessor::asyncMedia('screen', 1200));
        $this->assertSame('(max-width: 959px) and (min-width: 1024px), print and (min-width: 1024px)',
            CriticalCssProcessor::asyncMedia(' (max-width: 959px) ,print', 1024));
        $this->assertSame('print', CriticalCssProcessor::asyncMedia('not print', 1024));
        $this->assertSame('print', CriticalCssProcessor::asyncMedia('all', 0));

        $out = $this->optimize($this->html(), ['FOLD_BLOCKS' => 2, 'CRITICAL_DESKTOP_WIDTH' => 0]);
        $this->assertStringContainsString('rel="stylesheet" media="print" onload="vpsCss(this)"', $out);
    }

    public function testNothingChangesWithoutLocalCss(): void
    {
        $html = '<!DOCTYPE html><html><head><link rel="stylesheet" href="https://cdn.example/a.css"></head><body></body></html>';
        $this->assertSame($html, $this->optimize($html, [], false, $ctx));
        $this->assertSame('no-css', $ctx->stats['critical']);
    }

    public function testTooLargeCriticalIsNotApplied(): void
    {
        $html = $this->html();
        $this->assertSame($html, $this->optimize($html, ['CRITICAL_MAX_SIZE' => 10], false, $ctx));
        $this->assertSame('too_large', $ctx->stats['critical']);
    }

    public function testChangedCssRebuildsCritical(): void
    {
        $this->optimize($this->html());
        file_put_contents($this->root . '/tpl/css/main.css', '.header{height:80px}');
        touch($this->root . '/tpl/css/main.css', time() + 10);
        clearstatcache();
        $out = $this->optimize($this->html(), ['FOLD_BLOCKS' => 2], false, $ctx);
        $this->assertSame('built', $ctx->stats['critical']);
        $this->assertStringContainsString('.header{height:80px}', $out);
    }
}
