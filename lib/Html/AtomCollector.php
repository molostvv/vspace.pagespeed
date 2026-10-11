<?php

namespace Vspace\PageSpeed\Html;

/**
 * Собирает атомы (теги, классы, id) из участка разметки — обычно первого экрана. Атрибуты <html> и <body>
 * учитываются всегда: их классы часто участвуют в селекторах шапки.
 *
 * Скрытые при загрузке поддеревья (выпадающее меню, попап, форма поиска — FOLD_SKIP_CLASSES): атомы самого
 * элемента учитываются — правило, которое его скрывает, должно попасть в critical, — а содержимое нет:
 * внутри невидимого контейнера стили не нужны до первого действия пользователя.
 */
final class AtomCollector
{
    /** @param list<string> $skipClasses элементы с этими классами учитываются без содержимого */
    public function __construct(private readonly array $skipClasses = [])
    {
    }

    public function collect(Document $doc, int $from, int $to, ?AtomSet $set = null): AtomSet
    {
        $set ??= new AtomSet();
        $tags = $doc->tags();
        $count = count($tags);

        foreach ($tags as $tag) {
            if ($tag['pos'] >= $doc->bodyOpenEnd) {
                break;
            }
            if (!$tag['close'] && ($tag['name'] === 'html' || $tag['name'] === 'body')) {
                $this->addTag($set, $tag);
            }
        }

        for ($k = $doc->firstTagIndexAt($from); $k < $count; $k++) {
            $tag = $tags[$k];
            if ($tag['pos'] >= $to) {
                break;
            }
            if ($tag['close']) {
                continue;
            }
            $classes = $this->addTag($set, $tag);
            if ($this->skipClasses && $classes && array_intersect($classes, $this->skipClasses) !== []) {
                $k = $doc->elementEndIndex($k);
            }
        }
        return $set;
    }

    /**
     * Добавляет в набор элементы с классами $classes из любого места страницы — вместе с содержимым.
     * Для элементов, которые видны сразу, хотя в коде стоят ниже первого экрана: cookie-баннер,
     * фиксированные кнопки и плашки.
     * @param list<string> $classes
     */
    public function collectElements(Document $doc, array $classes, AtomSet $set): void
    {
        $tags = $doc->tags();
        $count = count($tags);
        for ($k = $doc->firstTagIndexAt($doc->bodyOpenEnd); $k < $count; $k++) {
            $tag = $tags[$k];
            if ($tag['pos'] >= $doc->bodyEnd) {
                break;
            }
            if ($tag['close'] || stripos($tag['attrs'], 'class') === false) {
                continue;
            }
            if (array_intersect(Tag::splitClasses(Tag::attrFromString($tag['attrs'], 'class')), $classes) !== []) {
                $end = $doc->elementEndIndex($k);
                $this->collect($doc, $tag['pos'], $tags[$end]['end'], $set);
                $k = $end;
            }
        }
    }

    /** @return list<string> классы тега */
    private function addTag(AtomSet $set, array $tag): array
    {
        $set->addTag($tag['name']);
        if ($tag['attrs'] === '') {
            return [];
        }
        $classes = Tag::splitClasses(Tag::attrFromString($tag['attrs'], 'class'));
        foreach ($classes as $class) {
            $set->addClass($class);
        }
        $id = Tag::attrFromString($tag['attrs'], 'id');
        if ($id !== null && $id !== '') {
            $set->addId(trim($id));
        }
        return $classes;
    }
}
