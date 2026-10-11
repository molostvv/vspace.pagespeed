<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Optimizer;

final class LazyImagesProcessorTest extends TestCase
{
    private const HTML = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>t</title><link rel="stylesheet" href="/a.css"></head><body>'
        . '<header class="header"><div class="top" style="background-image:url(/img/banner.jpg)"></div>'
        . '<nav class="navbar-dropdown"><img loading="lazy" src="/img/menu.png"><div style="background:url(/img/menu-bg.jpg)"></div></nav>'
        . '<img loading="lazy" src="/img/logo.png"></header>'
        . '<section class="page__content">'
        . '<div class="panel"><div class="hero-bg" style="background-image:url(&quot;/img/hero.jpg&quot;)"></div><img loading="lazy" src="/img/a.jpg"></div>'
        . '<div class="panel"><img loading="lazy" data-lazy="N" src="/img/keep.jpg"></div>'
        . '<div class="panel"><img src="/img/below.jpg"><img loading="lazy" src="/img/b.jpg"><iframe src="https://video.example/1"></iframe>'
        . '<div class="bg" style="height:10px;background-image:url(/img/c.jpg)"></div>'
        . '<div data-lazy="N" style="background-image:url(/img/d.jpg)"></div>'
        . '<div class="ammina-lazy" style="background-image:url(/img/e.jpg)"></div>'
        . '<img src="/img/prio.jpg" fetchpriority="high"></div>'
        . '</section></body></html>';

    private function optimize(string $html, ?Context &$ctx = null): string
    {
        $config = new Config([
            'ACTIVE' => 'Y',
            'CRITICAL_ACTIVE' => 'N',
            'FOLD_START_CLASSES' => 'page__content',
            'FOLD_BLOCK_CLASSES' => 'panel',
            'FOLD_BLOCKS' => 2,
            'FOLD_SKIP_CLASSES' => 'navbar-dropdown',
        ]);
        $ctx = new Context($config, sys_get_temp_dir());
        return Optimizer::create($config, sys_get_temp_dir())->optimize($html, $ctx);
    }

    public function testFoldAwareLazyLoading(): void
    {
        $out = $this->optimize(self::HTML, $ctx);
        $this->assertSame([], $ctx->errors);

        // первый экран: без lazy; фон hero — preload до первого ресурса в head; баннер в шапке — не hero
        $this->assertStringContainsString('<title>t</title><link rel="preload" as="image" href="/img/hero.jpg" fetchpriority="high"><link rel="stylesheet"', $out);
        $this->assertStringContainsString('<img src="/img/logo.png">', $out);
        $this->assertStringContainsString('<img src="/img/a.jpg">', $out);
        $this->assertStringContainsString('<img data-lazy="N" src="/img/keep.jpg">', $out);
        $this->assertStringContainsString('<div class="top" style="background-image:url(/img/banner.jpg)">', $out);
        $this->assertStringContainsString('style="background-image:url(&quot;/img/hero.jpg&quot;)"', $out);

        // скрытое меню и ниже первого экрана: lazy, фоны — в data-vps-bg
        $this->assertStringContainsString('<img loading="lazy" src="/img/menu.png">', $out);
        $this->assertStringContainsString('<div style="background:none" data-vps-bg="url(/img/menu-bg.jpg)">', $out);
        $this->assertStringContainsString('<img src="/img/below.jpg" loading="lazy">', $out);
        $this->assertStringContainsString('<img loading="lazy" src="/img/b.jpg">', $out);
        $this->assertStringContainsString('<iframe src="https://video.example/1" loading="lazy">', $out);
        $this->assertStringContainsString('<div class="bg" style="height:10px;background-image:none" data-vps-bg="url(/img/c.jpg)">', $out);

        // исключения
        $this->assertStringContainsString('<div data-lazy="N" style="background-image:url(/img/d.jpg)">', $out);
        $this->assertStringContainsString('<div class="ammina-lazy" style="background-image:url(/img/e.jpg)">', $out);
        $this->assertStringContainsString('<img src="/img/prio.jpg" fetchpriority="high">', $out);

        // загрузчик фонов — перед </body>
        $this->assertMatchesRegularExpression('~<script data-skip-moving="true">\(function\(w,d\)\{.*?\}\)\(window,document\);</script></body>~', $out);

        $this->assertSame(3, $ctx->stats['img_eager']);
        $this->assertSame(2, $ctx->stats['img_lazy']);
        $this->assertSame(2, $ctx->stats['img_bg']);
        $this->assertSame('/img/hero.jpg', $ctx->stats['hero']);
    }

    public function testNoLoaderWithoutDeferredBackgrounds(): void
    {
        $out = $this->optimize('<html><head></head><body><section class="page__content"><div class="panel"><img loading="lazy" src="/a.jpg"></div></section></body></html>', $ctx);
        $this->assertStringContainsString('<img src="/a.jpg">', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('preload', $out);
    }
}
