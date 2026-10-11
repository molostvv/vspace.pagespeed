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
