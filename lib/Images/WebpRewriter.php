<?php

namespace Vspace\PageSpeed\Images;

use Vspace\PageSpeed\Context;
use Vspace\PageSpeed\Css\UrlRebaser;
use Vspace\PageSpeed\Html\Tag;

/**
 * Подменяет в атрибутах тега адреса локальных JPEG/PNG на готовые WebP-копии (WebpStore). Копии ещё нет —
 * адрес остаётся, а картинка ставится в очередь агенту: страница никогда не ждёт конвертации.
 *
 * Атрибуты: src, srcset, poster, фоны в style и data-атрибуты, из которых картинку подставляют скрипты —
 * ленивые загрузчики (data-src, data-srcset, data-bg…) и скрипты сайта (data-image — random.js,
 * data-adapt — мобильная картинка плиток в article-list.js, data-poster — постеры видео на главной). Ссылки (href) не трогаются: это может быть
 * скачивание оригинала. Внешние адреса и адреса без файла на диске остаются как есть.
 */
final class WebpRewriter
{
    private const URL_ATTRS = ['src', 'data-src', 'poster', 'data-poster', 'data-image', 'data-adapt', 'data-bg', 'data-background', 'data-background-image'];
    private const SRCSET_ATTRS = ['srcset', 'data-srcset'];
    private const CSS_URL = '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i';

    /** Сколько адресов подменено, поставлено в очередь сейчас и уже ждут в очереди — для статистики. */
    public int $rewritten = 0;
    public int $queued = 0;
    public int $waiting = 0;

    /** @var array<string, ?string> адрес -> адрес копии (null — без замены) */
    private array $memo = [];

    public function __construct(private readonly WebpStore $store)
    {
    }

    /** Быстрая проверка строки атрибутов: есть ли в теге вообще JPEG/PNG. */
    public static function mayContain(string $attrs): bool
    {
        return stripos($attrs, '.jp') !== false || stripos($attrs, '.png') !== false;
    }

    /** @return bool изменён ли тег */
    public function rewrite(Tag $tag, Context $ctx): bool
    {
        $changed = false;
        foreach (self::URL_ATTRS as $name) {
            $value = $tag->get($name);
            if ($value === null || $value === '') {
                continue;
            }
            $new = stripos($value, 'url(') !== false ? $this->css($value, $ctx) : ($this->map(trim($value), $ctx) ?? $value);
            if ($new !== $value) {
                $tag->set($name, $new);
                $changed = true;
            }
        }
        foreach (self::SRCSET_ATTRS as $name) {
            $value = $tag->get($name);
            if ($value === null || $value === '') {
                continue;
            }
            // кандидаты через запятую: «адрес [дескриптор]»; адрес — первый непробельный кусок кандидата
            $new = preg_replace_callback('/(^|,)(\s*)([^\s,]+)/', fn(array $m): string => $m[1] . $m[2] . ($this->map($m[3], $ctx) ?? $m[3]), $value);
            if ($new !== null && $new !== $value) {
                $tag->set($name, $new);
                $changed = true;
            }
        }
        $style = $tag->get('style');
        if ($style !== null && stripos($style, 'url(') !== false && ($new = $this->css($style, $ctx)) !== $style) {
            $tag->set('style', $new);
            $changed = true;
        }
        return $changed;
    }

    private function css(string $css, Context $ctx): string
    {
        return preg_replace_callback(self::CSS_URL, function (array $m) use ($ctx): string {
            $new = $this->map(trim($m[2]), $ctx);
            return $new === null ? $m[0] : 'url(' . $m[1] . $new . $m[1] . ')';
        }, $css) ?? $css;
    }

    /** Адрес копии или null (не JPEG/PNG, внешний, файла нет, копия не готова или не нужна). */
    private function map(string $url, Context $ctx): ?string
    {
        if (!array_key_exists($url, $this->memo)) {
            $this->memo[$url] = $this->resolve($url, $ctx);
        }
        if ($this->memo[$url] !== null) {
            $this->rewritten++;
        }
        return $this->memo[$url];
    }

    private function resolve(string $url, Context $ctx): ?string
    {
        $cut = strcspn($url, '?#');
        $base = substr($url, 0, $cut);
        if (!preg_match('/\.(?:jpe?g|png)$/i', $base) || ($path = self::localPath($base, $ctx)) === null) {
            return null;
        }
        $file = rawurldecode($path);
        if (!WebpStore::validPath($file)) {
            return null;
        }
        switch ($this->store->status($file)) {
            case WebpStore::READY:
                return $this->store->url($path) . substr($url, $cut);
            case WebpStore::MISSING:
                $this->store->enqueue($file) ? $this->queued++ : $this->waiting++;
        }
        return null;
    }

    /** Путь от корня сайта для локального адреса: /a.jpg, a.jpg (от страницы; не во фрагменте), //свой-домен/a.jpg. */
    private static function localPath(string $url, Context $ctx): ?string
    {
        if (preg_match('#^(?:https?:)?//([^/:]+)(?::\d+)?(/.*)$#i', $url, $m)) {
            return in_array(strtolower($m[1]), $ctx->localHosts, true) ? UrlRebaser::normalize($m[2]) : null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) { // data:, blob: и прочие схемы
            return null;
        }
        if ($url[0] !== '/') {
            return $ctx->fragment ? null : UrlRebaser::normalize($ctx->pageDir() . $url);
        }
        return UrlRebaser::normalize($url);
    }
}
