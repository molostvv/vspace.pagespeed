<?php

namespace Vspace\PageSpeed\Html;

/**
 * Определяет границу «первого экрана» — позицию в HTML, до которой разметка считается видимой сразу после
 * загрузки. Всё, что выше границы (шапка, hero, первые блоки), должно получить стили из critical CSS.
 *
 * Порядок:
 *  1. Маркер <!--vps-fold--> в шаблоне/контенте — граница ровно по нему.
 *  2. Иначе считаются блоки контента (классы FOLD_BLOCK_CLASSES, по умолчанию panel / panel-hero / page-header),
 *     начиная с элемента с классом из FOLD_START_CLASSES (page__content). Граница — начало блока номер N+1,
 *     вложенные блоки не считаются.
 *  3. Граница не дальше FOLD_MAX_BYTES от начала контента (если контейнер контента не найден — от первого
 *     блока) — страховка для страниц без блоков (статьи), где иначе первым экраном стала бы вся страница.
 * Шапка до начала контента входит в первый экран всегда.
 */
final class FoldDetector
{
    public const MARKER = '<!--vps-fold-->';

    /**
     * @param list<string> $startClasses
     * @param list<string> $blockClasses
     */
    public function __construct(
        private readonly array $startClasses,
        private readonly array $blockClasses,
        private readonly int $blocks,
        private readonly int $maxBytes,
    ) {
    }

    /** @return array{start:int, boundary:int, reason:string} */
    public function detect(Document $doc): array
    {
        $html = $doc->html;
        $marker = strpos($html, self::MARKER, $doc->bodyOpenEnd);
        if ($marker !== false && $marker < $doc->bodyEnd) {
            return ['start' => $doc->bodyStart, 'boundary' => $marker, 'reason' => 'marker'];
        }

        $tags = $doc->tags();
        $count = count($tags);
        $first = $doc->firstTagIndexAt($doc->bodyOpenEnd);

        $contentIndex = $first;
        $contentStart = null;
        if ($this->startClasses) {
            for ($k = $first; $k < $count && $tags[$k]['pos'] < $doc->bodyEnd; $k++) {
                if (!$tags[$k]['close'] && $this->hasClass($tags[$k]['attrs'], $this->startClasses)) {
                    $contentIndex = $k + 1;
                    $contentStart = $tags[$k]['pos'];
                    break;
                }
            }
        }

        $boundary = null;
        $reason = 'end';
        if ($this->blockClasses) {
            $found = 0;
            $blockEnd = -1;
            for ($k = $contentIndex; $k < $count && $tags[$k]['pos'] < $doc->bodyEnd; $k++) {
                $tag = $tags[$k];
                if ($tag['close'] || $tag['pos'] < $blockEnd || !$this->hasClass($tag['attrs'], $this->blockClasses)) {
                    continue;
                }
                if ($found === $this->blocks) {
                    $boundary = $tag['pos'];
                    $reason = 'blocks';
                    break;
                }
                // без контейнера контента (другой шаблон) лимит считается от первого блока — шапка в него не входит
                $contentStart ??= $tag['pos'];
                $found++;
                $blockEnd = $tags[$doc->elementEndIndex($k)]['pos'];
            }
        }

        $boundary ??= $doc->bodyEnd;
        $contentStart ??= $doc->bodyOpenEnd;
        if ($this->maxBytes > 0 && $boundary > $contentStart + $this->maxBytes) {
            $boundary = $contentStart + $this->maxBytes;
            $reason = 'limit';
        }
        return ['start' => $doc->bodyStart, 'boundary' => $boundary, 'reason' => $reason];
    }

    /** @param list<string> $classes */
    private function hasClass(string $attrs, array $classes): bool
    {
        $value = Tag::attrFromString($attrs, 'class');
        return $value !== null && array_intersect(Tag::splitClasses($value), $classes) !== [];
    }
}
