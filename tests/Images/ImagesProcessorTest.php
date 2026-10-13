<?php

namespace Vspace\PageSpeed\Tests\Images;

use PHPUnit\Framework\TestCase;
use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Images\WebpStore;
use Vspace\PageSpeed\Optimizer;

final class ImagesProcessorTest extends TestCase
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

    /** Временный корень сайта: исходник и (если $copy не null) его WebP-копия с тем же временем. */
    private function site(array $images): string
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()) . '/vps-img-' . bin2hex(random_bytes(4));
        foreach ($images as $path => $copy) {
            @mkdir(dirname($root . $path), 0777, true);
            file_put_contents($root . $path, 'source');
            touch($root . $path, 1_700_000_000);
            if ($copy !== null) {
                $target = (new WebpStore($root))->targetPath($path);
                @mkdir(dirname($target), 0777, true);
                file_put_contents($target, $copy);
                touch($target, 1_700_000_000);
            }
        }
        return $root;
    }

    private function removeSite(string $root): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }

    private function optimizeWebp(string $html, string $root, bool $webpBrowser, array $options = [], ?Context &$ctx = null): string
    {
        $config = new Config($options + ['ACTIVE' => 'Y', 'CRITICAL_ACTIVE' => 'N', 'IMAGES_ACTIVE' => 'N', 'WEBP_ACTIVE' => 'Y']);
        $ctx = new Context($config, $root, '/services/seo/', ['example.ru', 'www.example.ru'], false, $webpBrowser);
        return Optimizer::create($config, $root)->optimize($html, $ctx);
    }

    public function testWebpCopiesReplaceImages(): void
    {
        $root = $this->site([
            '/img/a.jpg' => 'webp',
            '/img/b.png' => 'webp',
            '/services/seo/img/rel.jpg' => 'webp',
            '/img/new.jpg' => null,
            '/img/small.png' => '', // WebP не меньше — остаётся исходник
        ]);
        $html = '<!DOCTYPE html><html><head><meta property="og:image" content="https://example.ru/img/a.jpg"></head><body>'
            . '<img src="/img/a.jpg" srcset="/img/a.jpg 1x, /img/b.png?v=2 2x" alt="">'
            . '<div class="bg" style=\'background-image:url("/img/a.jpg")\'></div>'
            . '<span data-src="img/rel.jpg" data-image="https://example.ru/img/a.jpg" data-adapt="//cdn.other.ru/img/a.jpg"></span>'
            . '<img src="/img/new.jpg"><img src="/img/small.png"><img src="/img/missing.jpg"><img src="/img/a.gif">'
            . '<a href="/img/a.jpg">оригинал</a><img data-vps-skip src="/img/a.jpg">'
            . '</body></html>';
        try {
            $out = $this->optimizeWebp($html, $root, true, [], $ctx);
            $this->assertSame([], $ctx->errors);
            $w = '/upload/vspace.pagespeed/webp';

            $this->assertStringContainsString('<meta property="og:image" content="https://example.ru/img/a.jpg">', $out); // head не трогается
            $this->assertStringContainsString('<img src="' . $w . '/img/a.jpg.webp" srcset="' . $w . '/img/a.jpg.webp 1x, ' . $w . '/img/b.png.webp?v=2 2x" alt="">', $out);
            $this->assertStringContainsString('<div class="bg" style="background-image:url(&quot;' . $w . '/img/a.jpg.webp&quot;)">', $out);
            $this->assertStringContainsString('<span data-src="' . $w . '/services/seo/img/rel.jpg.webp" data-image="' . $w . '/img/a.jpg.webp" data-adapt="//cdn.other.ru/img/a.jpg">', $out);
            // копии нет — исходник и очередь; копия «не нужна», файла нет, не JPEG/PNG, ссылка, data-vps-skip — без изменений
            $this->assertStringContainsString('<img src="/img/new.jpg"><img src="/img/small.png"><img src="/img/missing.jpg"><img src="/img/a.gif">'
                . '<a href="/img/a.jpg">оригинал</a><img data-vps-skip src="/img/a.jpg">', $out);

            $this->assertSame(6, $ctx->stats['webp']);
            $this->assertSame(1, $ctx->stats['webp_queued']);
            $this->assertSame(1, (new WebpStore($root))->stats()['queue']);

            // повторный запрос: картинка уже в очереди
            $this->optimizeWebp($html, $root, true, [], $ctx);
            $this->assertArrayNotHasKey('webp_queued', $ctx->stats);
            $this->assertSame(1, $ctx->stats['webp_waiting']);
        } finally {
            $this->removeSite($root);
        }
    }

    public function testWebpExcludedImagesStayAsIs(): void
    {
        $root = $this->site(['/img/a.jpg' => 'webp', '/img/logos/l.png' => 'webp', '/img/logos/new.png' => null]);
        $html = '<!DOCTYPE html><html><head></head><body>'
            . '<img src="/img/a.jpg"><img src="/img/logos/l.png"><img src="/img/logos/new.png">'
            . '</body></html>';
        try {
            $out = $this->optimizeWebp($html, $root, true, ['WEBP_EXCLUDE_FILES' => "/img/logos/*.png\n/other/"], $ctx);
            $this->assertStringContainsString('<img src="/upload/vspace.pagespeed/webp/img/a.jpg.webp">', $out);
            // исключённые: ни готовая копия, ни очередь
            $this->assertStringContainsString('<img src="/img/logos/l.png"><img src="/img/logos/new.png">', $out);
            $this->assertSame(1, $ctx->stats['webp']);
            $this->assertArrayNotHasKey('webp_queued', $ctx->stats);
        } finally {
            $this->removeSite($root);
        }
    }

    public function testAjaxFragmentGetsOnlyWebp(): void
    {
        $root = $this->site(['/img/a.jpg' => 'webp', '/ajax/img/rel.jpg' => 'webp']);
        $html = "\n<div class=\"panel\" style=\"background-image:url(/img/a.jpg)\"><img src=\"/img/a.jpg\"><img src=\"img/rel.jpg\"></div>"
            . '<script>var s = "<img src=\'/img/a.jpg\'>";</script>';
        try {
            $config = new Config(['ACTIVE' => 'Y', 'IMAGES_ACTIVE' => 'Y', 'WEBP_ACTIVE' => 'Y', 'FOLD_BLOCK_CLASSES' => 'panel']);
            $ctx = new Context($config, $root, '/ajax/main-reviews.php', ['example.ru'], false, true, true);
            $out = Optimizer::create($config, $root, true)->optimize($html, $ctx);
            $w = '/upload/vspace.pagespeed/webp';
            // фон и абсолютный адрес — WebP; относительный (от какой страницы — неизвестно), lazy и скрипт — без изменений
            $this->assertSame("\n<div class=\"panel\" style=\"background-image:url(" . $w . "/img/a.jpg.webp)\"><img src=\"" . $w . "/img/a.jpg.webp\"><img src=\"img/rel.jpg\"></div>"
                . '<script>var s = "<img src=\'/img/a.jpg\'>";</script>', $out);
            $this->assertSame(2, $ctx->stats['webp']);
        } finally {
            $this->removeSite($root);
        }
    }

    public function testBrowserWithoutWebpGetsOriginals(): void
    {
        $root = $this->site(['/img/a.jpg' => 'webp', '/img/new.jpg' => null]);
        $html = '<!DOCTYPE html><html><head></head><body><img src="/img/a.jpg"><img src="/img/new.jpg"></body></html>';
        try {
            $this->assertSame($html, $this->optimizeWebp($html, $root, false, [], $ctx));
            $this->assertSame(0, (new WebpStore($root))->stats()['queue']);
        } finally {
            $this->removeSite($root);
        }
    }

    public function testWebpWithLazyLoading(): void
    {
        $root = $this->site(['/img/hero.jpg' => 'webp', '/img/c.jpg' => 'webp', '/img/below.jpg' => 'webp']);
        $html = '<!DOCTYPE html><html><head><link rel="stylesheet" href="/a.css"></head><body><section class="page__content">'
            . '<div class="panel" style="background-image:url(/img/hero.jpg)"></div>'
            . '<div class="panel"><img src="/img/below.jpg"><div class="bg" style="background-image:url(/img/c.jpg)"></div></div>'
            . '</section></body></html>';
        try {
            $out = $this->optimizeWebp($html, $root, true, [
                'IMAGES_ACTIVE' => 'Y', 'FOLD_START_CLASSES' => 'page__content', 'FOLD_BLOCK_CLASSES' => 'panel', 'FOLD_BLOCKS' => 1, 'FOLD_SKIP_CLASSES' => '',
            ], $ctx);
            $w = '/upload/vspace.pagespeed/webp';
            $this->assertStringContainsString('<link rel="preload" as="image" href="' . $w . '/img/hero.jpg.webp" fetchpriority="high"><link rel="stylesheet"', $out);
            $this->assertStringContainsString('<img src="' . $w . '/img/below.jpg.webp" loading="lazy">', $out);
            $this->assertStringContainsString('<div class="bg" style="background-image:none" data-vps-bg="url(' . $w . '/img/c.jpg.webp)">', $out);
            $this->assertSame(3, $ctx->stats['webp']);
        } finally {
            $this->removeSite($root);
        }
    }
}
