<?php

namespace Vspace\PageSpeed\Tests\Js;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Optimizer;

final class DeferJsProcessorTest extends TestCase
{
    private function optimize(string $html, array $options = [], ?Context &$ctx = null): string
    {
        $config = new Config($options + ['ACTIVE' => 'Y', 'CRITICAL_ACTIVE' => 'N', 'IMAGES_ACTIVE' => 'N', 'WEBP_ACTIVE' => 'N', 'JS_ACTIVE' => 'Y']);
        $ctx = new Context($config, sys_get_temp_dir(), '/services/seo/', ['example.ru']);
        return Optimizer::create($config, sys_get_temp_dir())->optimize($html, $ctx);
    }

    public function testScriptsAreDeferredInOrder(): void
    {
        $html = '<!DOCTYPE html><html><head><script data-skip-moving="true">var early=1;</script>'
            . '<script src="/head.js"></script></head><body><div>x</div>'
            . '<script>var a=1;</script>'
            . '<script type="text/javascript" src="/bitrix/js/main/core/core.js?1"></script>'
            . '<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU" async></script>'
            . '<script type="application/ld+json">{"@type":"Organization"}</script>'
            . '<script type="text/template"><b>tpl</b></script>'
            . '<script type="module" src="/m.js"></script><script nomodule src="/legacy.js"></script>'
            . '<script type="deferjs">ammina()</script>'
            . '<script data-vps-skip src="/keep.js"></script>'
            . '<script src="/local/templates/demis_edges/scripts/import/HeaderJilin/index.js?v=1"></script>'
            . '<script src="https://example.ru/local/templates/demis_edges/js/inp.min.js"></script>'
            . '</body></html>';
        $out = $this->optimize($html, [], $ctx);

        $this->assertSame([], $ctx->errors);
        $this->assertStringContainsString('<script data-skip-moving="true">var early=1;</script>', $out);
        $this->assertStringContainsString('<script src="/head.js" type="text/vps-defer"></script>', $out);
        $this->assertStringContainsString('<script type="text/vps-defer">var a=1;</script>', $out);
        $this->assertStringContainsString('<script type="text/vps-defer" src="/bitrix/js/main/core/core.js?1"></script>', $out);
        $this->assertStringContainsString('<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU" async type="text/vps-defer"></script>', $out);
        // не JS, модули, уже отложенные Ammina, data-vps-skip и исключённые файлы (локально и по своему домену) — как были
        foreach (['<script type="application/ld+json">', '<script type="text/template">', '<script type="module" src="/m.js">',
                     '<script nomodule src="/legacy.js">', '<script type="deferjs">', '<script data-vps-skip src="/keep.js">',
                     '<script src="/local/templates/demis_edges/scripts/import/HeaderJilin/index.js?v=1">',
                     '<script src="https://example.ru/local/templates/demis_edges/js/inp.min.js">'] as $kept) {
            $this->assertStringContainsString($kept, $out);
        }
        // загрузчик — перед </body>, после всех скриптов
        $this->assertMatchesRegularExpression('~<script data-skip-moving="true">\(function\(w,d\)\{var T="text/vps-defer";.*?\}\)\(window,document\);</script></body>~', $out);
        $this->assertSame(4, $ctx->stats['js_deferred']);
        $this->assertSame(4, $ctx->stats['js_kept']); // data-skip-moving, data-vps-skip, два исключённых файла
    }

    public function testBitrixScriptsUseMinifiedCopies(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/vps-js-' . bin2hex(random_bytes(4));
        mkdir($root . '/bitrix/js/main/core', 0777, true);
        mkdir($root . '/local/js', 0777, true);
        $files = [
            '/bitrix/js/main/core/core.js' => [100, 'full'], '/bitrix/js/main/core/core.min.js' => [200, 'min'],
            '/bitrix/js/old.js' => [300, 'full'], '/bitrix/js/old.min.js' => [100, 'min'],   // копия старше — не берётся
            '/bitrix/js/empty.js' => [100, 'full'], '/bitrix/js/empty.min.js' => [200, ''],  // пустая — не берётся
            '/local/js/app.js' => [100, 'full'], '/local/js/app.min.js' => [200, 'min'],     // не /bitrix/ — не трогается
        ];
        foreach ($files as $path => [$time, $body]) {
            file_put_contents($root . $path, $body);
            touch($root . $path, 1700000000 + $time);
        }
        $html = '<html><head></head><body>'
            . '<script src="/bitrix/js/main/core/core.js?1754"></script>'
            . '<script src="/bitrix/js/old.js"></script><script src="/bitrix/js/empty.js"></script>'
            . '<script src="/local/js/app.js"></script><script src="/bitrix/js/main/core/core.min.js"></script>'
            . '<script data-skip-moving="true" src="/bitrix/js/main/core/core.js"></script>'
            . '</body></html>';
        $config = new Config(['ACTIVE' => 'Y', 'CRITICAL_ACTIVE' => 'N', 'IMAGES_ACTIVE' => 'N', 'WEBP_ACTIVE' => 'N', 'JS_ACTIVE' => 'Y']);
        $ctx = new Context($config, $root, '/', ['example.ru']);
        try {
            $out = Optimizer::create($config, $root)->optimize($html, $ctx);
        } finally {
            foreach (array_keys($files) as $path) {
                unlink($root . $path);
            }
            foreach (['/bitrix/js/main/core', '/bitrix/js/main', '/bitrix/js', '/bitrix', '/local/js', '/local', ''] as $dir) {
                rmdir($root . $dir);
            }
        }

        $this->assertStringContainsString('<script src="/bitrix/js/main/core/core.min.js?1754" type="text/vps-defer"></script>', $out);
        $this->assertStringContainsString('<script src="/bitrix/js/old.js" type="text/vps-defer"></script>', $out);
        $this->assertStringContainsString('<script src="/bitrix/js/empty.js" type="text/vps-defer"></script>', $out);
        $this->assertStringContainsString('<script src="/local/js/app.js" type="text/vps-defer"></script>', $out);
        $this->assertStringContainsString('<script src="/bitrix/js/main/core/core.min.js" type="text/vps-defer"></script>', $out);
        // неоткладываемый скрипт тоже получает копию
        $this->assertStringContainsString('<script data-skip-moving="true" src="/bitrix/js/main/core/core.min.js"></script>', $out);
        $this->assertSame(2, $ctx->stats['js_min']);
    }

    public function testNothingToDefer(): void
    {
        $html = '<!DOCTYPE html><html><head><script data-skip-moving="true">var a=1;</script></head><body><script type="application/ld+json">{}</script></body></html>';
        $this->assertSame($html, $this->optimize($html, [], $ctx));
        $this->assertArrayNotHasKey('js_deferred', $ctx->stats);
    }

    public function testDisabled(): void
    {
        $html = '<!DOCTYPE html><html><head></head><body><script src="/a.js"></script></body></html>';
        $this->assertSame($html, $this->optimize($html, ['JS_ACTIVE' => 'N']));
    }
}
