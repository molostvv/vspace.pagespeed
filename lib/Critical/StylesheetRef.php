<?php

namespace Vspace\PageSpeed\Critical;

/**
 * Подключение CSS на странице, которое уходит в critical и становится асинхронным: <link rel="stylesheet">
 * на локальный файл или крупный <style> из <head> (его содержимое сохраняется в файл кеша).
 */
final class StylesheetRef
{
    public const LINK = 'link';
    public const STYLE = 'style';

    /** Файл проиндексирован и вошёл в critical — значит, подключение можно делать асинхронным. */
    public bool $usable = false;

    /**
     * @param int $start начало заменяемого фрагмента HTML (тег link или элемент style целиком)
     * @param int $end конец фрагмента
     * @param string $urlPath файл от корня сайта, из которого строится critical
     * @param string $href адрес для подключения (у link — исходный href с ?версией)
     * @param string|null $media условие media, если не all
     * @param string $html исходный фрагмент (у link — для <noscript>)
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $start,
        public readonly int $end,
        public readonly string $urlPath,
        public readonly string $href,
        public readonly ?string $media,
        public readonly string $html,
    ) {
    }
}
