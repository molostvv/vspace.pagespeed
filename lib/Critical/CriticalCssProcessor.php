<?php

namespace Vspace\PageSpeed\Critical;

use Vspace\PageSpeed\Config;
use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Css\CriticalExtractor;
use Vspace\PageSpeed\Css\UrlRebaser;
use Vspace\PageSpeed\Html\Document;
use Vspace\PageSpeed\Html\Edits;
use Vspace\PageSpeed\Html\Tag;
use Vspace\PageSpeed\PathMatcher;
use Vspace\PageSpeed\Processor\ProcessorInterface;
use Vspace\PageSpeed\Storage\FileStorage;

/**
 * Critical CSS: стили первого экрана — инлайном в <head>, полные CSS — асинхронно.
 *
 * Что считается подключением CSS: <link rel="stylesheet"> на локальный .css (в head и body, кроме файлов
 * из EXCLUDE_FILES и media=print) и при INLINE_STYLES=Y крупные <style> из head (от INLINE_STYLES_MIN байт) —
 * их содержимое сохраняется в файл кеша и подключается так же, как обычный CSS. Внешние CSS (другой домен)
 * не трогаются.
 *
 * Результат на странице:
 *   <link rel="preload" href="main.css" as="style">          — ASYNC_MODE=preload: загрузка с высоким приоритетом
 *   <style data-vps-critical="…">…critical…</style>           — на месте первого подключения CSS в head
 *   <script data-skip-moving="true">…vpsCss…</script>          — включает полный CSS
 *   <link rel="stylesheet" href="main.css" media="(min-width: 1024px)" onload="vpsCss(this)" onerror="vpsCss(this)"
 *         data-vps-async data-vps-media="all">
 *   <noscript><link rel="stylesheet" href="main.css"></noscript>
 * Порядок подключений не меняется, поэтому каскад после загрузки полного CSS тот же, что без модуля.
 *
 * Асинхронно — только на узких экранах (CRITICAL_DESKTOP_WIDTH, см. asyncMedia): на компьютере полный CSS
 * блокирует первую отрисовку, как без модуля.
 *
 * Полный CSS включается сразу весь — когда загрузятся все файлы (запасной вариант — событие load окна).
 * Если включать каждый файл по мере загрузки, файл, который пришёл раньше, но стоит в каскаде раньше другого
 * (main.min.css перед page-articles.min.css), на время перебивает critical — вёрстка первого экрана дёргается
 * (CLS на /articles/ ~0.09 на телефоне). Без модуля такого промежуточного состояния нет: CSS блокирует отрисовку.
 *
 * Critical рассчитан на первый экран. Если страница открывается не сверху — перезагрузка или «назад» с прокруткой
 * ниже первого экрана (браузер восстанавливает позицию), адрес с #якорем, — браузер сразу показал бы середину
 * страницы без стилей, и она прыгала бы, пока приходит полный CSS (CLS 1.9 против 1.0 без модуля). Поэтому в этих
 * случаях страница скрыта (visibility на <html>) до включения полного CSS и конца отложенного JS (слайдеры до
 * запуска JS выстроены в ряд узкими слайдами и вдвое выше, потом сворачиваются), не дольше 3 с. Пока страница
 * скрыта, загрузчик JS не ждёт первой отрисовки (её нет) и стартует сразу после onload (window.vpsHold).
 * При показе прокрутка восстанавливается по элементу, который был в центре экрана: при уходе со страницы
 * (pagehide) в sessionStorage запоминаются прокрутка, путь к этому элементу и его высота на экране, при показе
 * он возвращается на ту же высоту. Браузер восстанавливал позицию по скрытой, ещё не свёрстанной странице и
 * промахивался на сотни пикселей; по пикселям — тоже: картинки выше экрана после перезагрузки ещё не загружены.
 * Элемент не нашёлся — прежняя прокрутка. После показа ещё 4 с (до первого действия пользователя) модуль держит
 * этот элемент на месте, если страница меняет высоту: на главной скрипты после загрузки дописывают блоки выше
 * экрана (AJAX), а браузер сам содержимое не удерживает. К #якорю — прокрутка заново, уже по полной вёрстке.
 */
final class CriticalCssProcessor implements ProcessorInterface
{
    /** @param FileStorage $publicStorage каталог, который отдаётся браузеру (Optimizer::PUBLIC_DIR) */
    public function __construct(
        private readonly Config $config,
        private readonly CriticalCssBuilder $builder,
        private readonly FileStorage $publicStorage,
    ) {
    }

    public function name(): string
    {
        return 'critical';
    }

    public function process(Document $doc, Edits $edits, Context $ctx): void
    {
        if ($ctx->skipCritical || !$this->config->criticalFor($ctx->pagePath)) {
            $ctx->stats['critical'] = 'excluded';
            return;
        }
        $refs = $this->collect($doc, $ctx);
        if (!$refs) {
            $ctx->stats['critical'] = 'no-css';
            return;
        }
        $result = $this->builder->build($refs, $ctx->foldAtoms($doc));
        $ctx->stats['critical'] = $result['status'];
        if ($result['css'] === null) {
            return;
        }
        $usable = array_values(array_filter($refs, static fn(StylesheetRef $ref): bool => $ref->usable));
        $ctx->stats['critical_bytes'] = strlen($result['css']);
        $ctx->stats['css_files'] = count($usable);

        $insertAt = $doc->headEnd;
        foreach ($usable as $ref) {
            if ($ref->start < $doc->headEnd) {
                $insertAt = min($insertAt, $ref->start);
            }
        }

        $head = '';
        if ($this->config->asyncMode === 'preload' && !$ctx->previewCritical) {
            foreach ($usable as $ref) {
                $head .= '<link rel="preload" href="' . self::escape($ref->href) . '" as="style"'
                    . ($ref->media !== null ? ' media="' . self::escape($ref->media) . '"' : '') . '>';
            }
        }
        $head .= '<style data-vps-critical="' . substr($result['signature'], 0, 8) . '">'
            . str_ireplace('</style', '<\/style', $result['css']) . '</style>';
        if (!$ctx->previewCritical) {
            $head .= '<script data-skip-moving="true">' . self::loaderScript(count($usable)) . '</script>';
        }
        $edits->insert($insertAt, $head);

        foreach ($usable as $ref) {
            $edits->replace($ref->start, $ref->end, $ctx->previewCritical ? '' : $this->asyncMarkup($ref));
        }
    }

    /** @return list<StylesheetRef> */
    private function collect(Document $doc, Context $ctx): array
    {
        $refs = [];
        $tags = $doc->tags();
        for ($k = $doc->firstTagIndexAt($doc->headOpenEnd), $count = count($tags); $k < $count; $k++) {
            $t = $tags[$k];
            if ($t['pos'] >= $doc->bodyEnd) {
                break;
            }
            if ($t['close']) {
                continue;
            }
            if ($t['name'] === 'link') {
                $ref = $this->linkRef($doc, $t, $ctx);
            } elseif ($t['name'] === 'style' && $this->config->inlineStyles && $t['pos'] < $doc->headEnd) {
                $ref = $this->styleRef($doc, $t, $ctx);
            } else {
                continue;
            }
            if ($ref !== null) {
                $refs[] = $ref;
            }
        }
        return $refs;
    }

    private function linkRef(Document $doc, array $t, Context $ctx): ?StylesheetRef
    {
        if (stripos($t['attrs'], 'stylesheet') === false) {
            return null;
        }
        $html = substr($doc->html, $t['pos'], $t['end'] - $t['pos']);
        $tag = Tag::parse($html);
        $rel = ' ' . strtolower((string)$tag?->get('rel')) . ' ';
        if ($tag === null || !str_contains($rel, ' stylesheet ') || str_contains($rel, ' alternate ')
            || $tag->has('disabled') || $tag->has('data-vps-skip')
        ) {
            return null;
        }
        $href = trim((string)$tag->get('href'));
        $media = $this->media($tag);
        if ($href === '' || $media === false) {
            return null;
        }
        $path = $this->localPath($href, $ctx);
        if ($path === null || PathMatcher::matchPath($path, $this->config->excludeFiles)) {
            return null;
        }
        return new StylesheetRef(StylesheetRef::LINK, $t['pos'], $t['end'], $path, $href, $media, $html);
    }

    private function styleRef(Document $doc, array $t, Context $ctx): ?StylesheetRef
    {
        $element = $doc->rawElement($t['pos']);
        if ($element === null || $element['contentEnd'] - $element['contentStart'] < $this->config->inlineStylesMin) {
            return null;
        }
        $tag = Tag::parse(substr($doc->html, $t['pos'], $t['end'] - $t['pos']));
        $type = strtolower(trim((string)$tag?->get('type')));
        $media = $tag === null ? false : $this->media($tag);
        if ($tag === null || $media === false || $tag->has('data-vps-skip') || $tag->has('data-vps-critical')
            || ($type !== '' && $type !== 'text/css')
        ) {
            return null;
        }
        $css = substr($doc->html, $element['contentStart'], $element['contentEnd'] - $element['contentStart']);
        // относительные url() во встроенном стиле считаются от адреса страницы — в файле кеша они бы сломались
        $css = UrlRebaser::rebase($css, $ctx->pageDir());
        $hash = md5($css);
        $relative = substr($hash, 0, 2) . '/' . $hash . '.css';
        if (!$this->publicStorage->exists($relative) && !$this->publicStorage->write($relative, $css)) {
            return null;
        }
        $this->publicStorage->touch($relative);
        $url = $this->publicStorage->url($relative);
        return new StylesheetRef(StylesheetRef::STYLE, $t['pos'], $element['end'], $url, $url, $media, '');
    }

    /** null — media не задано или all; false — только печать (такой CSS не блокирует отрисовку, не трогаем). */
    private function media(Tag $tag): string|false|null
    {
        $media = trim((string)$tag->get('media'));
        if ($media === '' || strtolower($media) === 'all') {
            return null;
        }
        return CriticalExtractor::isPrintOnly($media) ? false : $media;
    }

    /** Путь локального .css от корня сайта или null (внешний домен, не CSS, нет файла). */
    private function localPath(string $href, Context $ctx): ?string
    {
        if (preg_match('~^(?:https?:)?//([^/?#]+)(.*)$~i', $href, $m)) {
            if (!in_array(strtolower($m[1]), $ctx->localHosts, true)) {
                return null;
            }
            $href = $m[2] === '' ? '/' : $m[2];
        } elseif (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        } elseif (!str_starts_with($href, '/')) {
            $href = UrlRebaser::resolve($href, $ctx->pageDir());
        }
        $path = rawurldecode((string)strtok($href, '?#'));
        if (!str_ends_with(strtolower($path), '.css') || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }
        return is_file($ctx->documentRoot . $path) ? $path : null;
    }

    private function asyncMarkup(StylesheetRef $ref): string
    {
        if ($ref->kind === StylesheetRef::LINK) {
            $original = $ref->html;
            $tag = Tag::parse($ref->html);
        } else {
            $original = '<link rel="stylesheet" href="' . self::escape($ref->href) . '"'
                . ($ref->media !== null ? ' media="' . self::escape($ref->media) . '"' : '') . '>';
            $tag = Tag::parse($original);
        }
        $tag->set('media', self::asyncMedia($ref->media, $this->config->criticalDesktopWidth))
            ->set('onload', 'vpsCss(this)')
            ->set('onerror', 'vpsCss(this)')
            ->set('data-vps-async', null)
            ->set('data-vps-media', $ref->media ?? 'all');
        return $tag->toHtml() . '<noscript>' . $original . '</noscript>';
    }

    /**
     * media асинхронного подключения. На экранах от $width px условие выполняется, и файл блокирует первую
     * отрисовку, как без модуля: на компьютере critical почти не ускоряет показ (быстрая сеть), зато включение
     * полного CSS после показа — пересчёт стилей и раскладки всей страницы одной длинной задачей (PSI desktop,
     * /articles/: TBT 500–900 мс, без critical — 20–90). На узких экранах условие не выполняется: файл грузится
     * без блокировки, как с media=print, и включается загрузчиком. 0 — print на всех экранах.
     * Свой media ссылки сохраняется: условие дописывается к каждому запросу списка; запрос с not — print
     * (дописанное условие поменяло бы его смысл).
     */
    public static function asyncMedia(?string $media, int $width): string
    {
        if ($width <= 0) {
            return 'print';
        }
        $condition = '(min-width: ' . $width . 'px)';
        $queries = [];
        foreach (explode(',', $media ?? '') as $query) {
            $query = trim($query);
            if ($query === '' || strcasecmp($query, 'all') === 0) {
                $queries[] = $condition;
            } elseif (preg_match('~^not\b~i', $query)) {
                return 'print';
            } else {
                $queries[] = $query . ' and ' . $condition;
            }
        }
        return implode(', ', array_unique($queries));
    }

    /**
     * Включает все асинхронные CSS разом, когда загрузятся (или не загрузятся) все $count файлов. Ссылки стоят
     * после скрипта, поэтому к моменту последней загрузки все они уже в DOM. Страницу, открытую не сверху,
     * скрывает до этого момента (см. описание класса).
     */
    private static function loaderScript(int $count): string
    {
        return '(function(w,d,n){var c=0,done=0,hold=0,a0=null,h=d.documentElement,L=location,k="vps_y:"+L.pathname+L.search;'
            // держать элемент на высоте y, пока страница меняет размер, — 4 с или до действия пользователя
            . 'function keep(e,y){if(!w.ResizeObserver)return;var on=1,o=new ResizeObserver(function(){if(on){var dy=e.getBoundingClientRect().top-y;if(dy>1||dy<-1)w.scrollBy(0,dy)}});'
            . 'function end(){if(on){on=0;o.disconnect()}}o.observe(d.body);setTimeout(end,4000);'
            . '"wheel touchstart keydown mousedown".split(" ").forEach(function(t){w.addEventListener(t,end,{passive:true,once:true})})}'
            . 'function show(){if(!hold)return;hold=0;w.vpsHold=0;'
            // a0 = [прокрутка, путь к элементу из центра экрана, его высота на экране]
            . 'if(a0){try{var e=d.body,i,q=a0[1]?a0[1].split(","):[];for(i=0;e&&i<q.length;i++)e=e.children[q[i]];'
            . 'if(e&&q.length){w.scrollTo(0,w.pageYOffset+e.getBoundingClientRect().top-a0[2]);keep(e,a0[2])}else w.scrollTo(0,a0[0])}catch(x){}}'
            . 'else if(L.hash.length>1){try{var t=d.getElementById(decodeURIComponent(L.hash.slice(1)));if(t)t.scrollIntoView()}catch(x){}}'
            . 'h.style.visibility=""}'
            . 'try{var s=w.sessionStorage,p=w.performance,v=p&&p.getEntriesByType?p.getEntriesByType("navigation")[0]:null,'
            . 'r=(s.getItem(k)||"").split("|"),y=+r[0]||0;'
            . 'w.addEventListener("pagehide",function(){try{var e=d.elementFromPoint(w.innerWidth/2,w.innerHeight/2),'
            . 't=e?Math.round(e.getBoundingClientRect().top):0,q=[],j,x;'
            . 'while(e&&e!==d.body&&e.parentElement){for(j=0,x=e;(x=x.previousElementSibling);)j++;q.unshift(j);e=e.parentElement}'
            . 's.setItem(k,Math.round(w.pageYOffset)+"|"+(e===d.body?q.join(","):"")+"|"+t)}catch(x){}});'
            . 'if(v&&(v.type==="reload"||v.type==="back_forward")&&y>w.innerHeight){hold=1;a0=[y,r[1],+r[2]||0]}'
            . 'else if(L.hash.length>1)hold=1;'
            . 'if(hold){w.vpsHold=1;h.style.visibility="hidden";setTimeout(show,3000);'
            // показать, когда включён полный CSS, страница разобрана и (если есть) отложенный JS выполнен
            . 'w.vpsHoldCheck=function(){if(done&&d.readyState!=="loading"&&(!w.vpsJs||w.vpsJsDone))show()};'
            . 'd.addEventListener("DOMContentLoaded",w.vpsHoldCheck)}'
            . '}catch(x){}'
            . 'function all(){if(done)return;done=1;'
            . 'var a=d.querySelectorAll("link[data-vps-async]");'
            . 'for(var i=0;i<a.length;i++)a[i].media=a[i].getAttribute("data-vps-media")||"all";if(w.vpsHoldCheck)w.vpsHoldCheck()}'
            . 'w.vpsCss=function(l){l.onload=l.onerror=null;if(++c>=n)all()};'
            . 'w.addEventListener("load",all)})(window,document,' . $count . ');';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
