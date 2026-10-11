<?php

namespace Vspace\PageSpeed\Images;

use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Html\Tag;
use Vspace\PageSpeed\Processor\ProcessorInterface;

/**
 * Картинки относительно первого экрана (та же граница, что у critical CSS, — Context::fold()).
 *
 * В первом экране:
 *  - у <img> снимается loading="lazy" (его ставит на все картинки сайтовый COptimisationImage) — картинки,
 *    которые видны сразу, не должны ждать проверки видимости;
 *  - фон hero (первая фоновая картинка в style после начала контента — на сайте это LCP) получает в head
 *    <link rel="preload" as="image" fetchpriority="high">: без него браузер узнаёт о фоне только после
 *    разбора CSS и раскладки.
 * Ниже первого экрана и внутри скрытых при загрузке элементов (FOLD_SKIP_CLASSES — меню, попапы):
 *  - <img> и <iframe> без атрибута loading получают loading="lazy";
 *  - фон из style переносится в data-vps-bg, перед </body> ставится скрипт (IntersectionObserver, запас
 *    300px), который вернёт фон, когда элемент подойдёт к экрану. Без ожидания window.onload; элементы,
 *    которые появятся позже (клоны слайдеров), подхватывает MutationObserver.
 *
 * Не трогаются: элементы с data-vps-skip, картинки с data-lazy="N" (соглашение сайта — не откладывать),
 * <img fetchpriority="high"> и разметка lazyload Ammina (классы ammina-laz*), если её не выключили.
 */
final class LazyImagesProcessor implements ProcessorInterface
{
    /**
     * Отложенные фоны: data-vps-bg -> style.backgroundImage при подходе к экрану. Слайды каруселей обрезаны
     * её overflow и сами к экрану не «подходят» — как только виден любой слайд, загружаются все слайды
     * карусели (и клоны, которые она добавит позже), иначе при пролистывании слайд был бы пустым.
     */
    private const LOADER = '(function(w,d){var G=".owl-carousel,.swiper,.swiper-container,.slick-slider";'
        . 'function show(e){var v=e.getAttribute("data-vps-bg");if(v!==null){e.style.backgroundImage=v;e.removeAttribute("data-vps-bg")}}'
        . 'if(!("IntersectionObserver" in w)){var a=d.querySelectorAll("[data-vps-bg]");for(var i=0;i<a.length;i++)show(a[i]);return}'
        . 'var o=new IntersectionObserver(function(es){for(var i=0;i<es.length;i++)if(es[i].isIntersecting){var t=es[i].target,g=t.closest&&t.closest(G),a=g?g.querySelectorAll("[data-vps-bg]"):[t];'
        . 'if(g)g.vpsShown=1;for(var j=0;j<a.length;j++){o.unobserve(a[j]);show(a[j])}}},{rootMargin:"300px"});'
        . 'function add(e){var g=e.closest&&e.closest(G);if(g&&g.vpsShown)show(e);else o.observe(e)}'
        . 'function scan(r){if(r.hasAttribute&&r.hasAttribute("data-vps-bg"))add(r);var a=r.querySelectorAll?r.querySelectorAll("[data-vps-bg]"):[];for(var i=0;i<a.length;i++)add(a[i])}'
        . 'scan(d);if("MutationObserver" in w)new MutationObserver(function(ms){for(var i=0;i<ms.length;i++)for(var j=0;j<ms[i].addedNodes.length;j++)if(ms[i].addedNodes[j].nodeType===1)scan(ms[i].addedNodes[j])})'
        . '.observe(d.documentElement,{childList:true,subtree:true})})(window,document);';

    public function __construct(private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'images';
    }

    public function process(Document $doc, Edits $edits, Context $ctx): void
    {
        $fold = $ctx->fold($doc);
        $tags = $doc->tags();
        $count = count($tags);
        $skipClasses = $this->config->foldSkipClasses;
        $hiddenUntil = -1;
        $hero = null;
        $stats = ['eager' => 0, 'lazy' => 0, 'bg' => 0];

        for ($k = $doc->firstTagIndexAt($doc->bodyOpenEnd); $k < $count; $k++) {
            $t = $tags[$k];
            if ($t['pos'] >= $doc->bodyEnd) {
                break;
            }
            if ($t['close'] || $t['attrs'] === '') {
                continue;
            }
            $attrs = $t['attrs'];
            if ($skipClasses && $t['pos'] >= $hiddenUntil && stripos($attrs, 'class') !== false
                && array_intersect(Tag::splitClasses(Tag::attrFromString($attrs, 'class')), $skipClasses) !== []) {
                $hiddenUntil = $tags[$doc->elementEndIndex($k)]['end'];
            }
            $isMedia = $t['name'] === 'img' || $t['name'] === 'iframe';
            $hasBackground = stripos($attrs, 'url(') !== false && stripos($attrs, 'style') !== false;
            if ((!$isMedia && !$hasBackground) || stripos($attrs, 'data-vps-skip') !== false || stripos($attrs, 'ammina-laz') !== false) {
                continue;
            }
            $visible = $t['pos'] < $fold['boundary'] && $t['pos'] >= $hiddenUntil;
            $keep = strcasecmp((string)Tag::attrFromString($attrs, 'data-lazy'), 'N') === 0;

            $tag = Tag::parse(substr($doc->html, $t['pos'], $t['end'] - $t['pos']));
            if ($tag === null) {
                continue;
            }
            $changed = false;

            if ($isMedia) {
                $loading = strtolower((string)$tag->get('loading'));
                if ($visible && $loading === 'lazy') {
                    $tag->remove('loading');
                    $changed = true;
                    $stats['eager']++;
                } elseif (!$visible && !$keep && !$tag->has('loading') && strtolower((string)$tag->get('fetchpriority')) !== 'high') {
                    $tag->set('loading', 'lazy');
                    $changed = true;
                    $stats['lazy']++;
                }
            }

            if ($hasBackground && ($style = $tag->get('style')) !== null) {
                if ($visible) {
                    if ($hero === null && $fold['content'] !== null && $t['pos'] >= $fold['content']) {
                        $hero = InlineBackground::firstUrl($style);
                    }
                } elseif (!$keep && ($deferred = InlineBackground::defer($style)) !== null) {
                    $tag->set('style', $deferred[0])->set('data-vps-bg', $deferred[1]);
                    $changed = true;
                    $stats['bg']++;
                }
            }

            if ($changed) {
                $edits->replace($t['pos'], $t['end'], $tag->toHtml());
            }
        }

        if ($hero !== null) {
            $edits->insert(self::headResourcesStart($doc),
                '<link rel="preload" as="image" href="' . htmlspecialchars($hero, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '" fetchpriority="high">');
            $ctx->stats['hero'] = $hero;
        }
        if ($stats['bg'] > 0) {
            $edits->insert($doc->bodyEnd, '<script data-skip-moving="true">' . self::LOADER . '</script>');
        }
        foreach ($stats as $key => $value) {
            if ($value > 0) {
                $ctx->stats['img_' . $key] = $value;
            }
        }
    }

    /** Позиция первого link/style/script в head: preload должен стоять раньше всех ресурсов. */
    private static function headResourcesStart(Document $doc): int
    {
        $tags = $doc->tags();
        $count = count($tags);
        for ($k = $doc->firstTagIndexAt($doc->headOpenEnd); $k < $count && $tags[$k]['pos'] < $doc->headEnd; $k++) {
            if (!$tags[$k]['close'] && in_array($tags[$k]['name'], ['link', 'style', 'script'], true)) {
                return $tags[$k]['pos'];
            }
        }
        return $doc->headEnd;
    }
}
