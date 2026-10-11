<?php

namespace Vspace\PageSpeed\Css;

use Vspace\PageSpeed\Html\AtomSet;

/**
 * Собирает critical CSS из проиндексированных таблиц стилей (StylesheetIndexer) по набору атомов первого экрана.
 *
 * Правило попадает в critical, если хотя бы одна альтернатива его селектора выполнима на первом экране;
 * в вывод идут только выполнимые альтернативы (из «.a,.b{...}» при наличии одного .a останется «.a{...}»).
 * Порядок правил сохраняется — каскад тот же, что в исходных файлах.
 *
 * Одинаковые правила (тот же контекст @media/@supports, селекторы и тело) выводятся один раз — последнее
 * вхождение: у него та же специфичность и более поздний порядок, поэтому более ранняя копия ни на что не
 * влияет. Такие копии дают наборы стилей страниц, которые заново включают шапку, базу и т. п.
 * (page-articles.min.css повторяет main.min.css — на /articles/ это 38% critical).
 *
 *  - @media/@supports/@layer/@container — рекурсивно, обёртка выводится, если внутри что-то осталось;
 *    @media print отбрасывается целиком;
 *  - @font-face — только для семейств, которые используются в отобранных правилах (FONT_FACE=used),
 *    при FONT_DISPLAY_SWAP=Y без font-display получает font-display:swap (текст виден сразу);
 *  - @keyframes — только те, на которые ссылаются отобранные правила;
 *  - @page, @viewport, @charset, @import, @namespace — не нужны для первой отрисовки.
 */
final class CriticalExtractor
{
    private const KEEP_AT = ['counter-style' => true, 'property' => true, 'font-feature-values' => true, 'font-palette-values' => true];

    /** Узлы вывода: [OUT_RULE, текст правила], [OUT_GROUP, «@media ...», дочерние], [OUT_TEXT, текст]. */
    private const OUT_RULE = 0;
    private const OUT_GROUP = 1;
    private const OUT_TEXT = 2;

    /** @var list<string> */
    private array $fontFaces = [];
    /** @var list<array{0:string,1:string,2:string}> */
    private array $keyframes = [];

    public function __construct(
        private readonly bool $dropInteraction = true,
        private readonly string $fontFaceMode = 'used',
        private readonly bool $fontDisplaySwap = true,
    ) {
    }

    /**
     * @param list<array{nodes: array, media: ?string}> $sheets таблицы стилей в порядке подключения на странице
     */
    public function extract(array $sheets, AtomSet $atoms): string
    {
        $this->fontFaces = [];
        $this->keyframes = [];

        $tree = [];
        foreach ($sheets as $sheet) {
            $nodes = $this->walk($sheet['nodes'], $atoms);
            $media = trim((string)($sheet['media'] ?? ''));
            if ($nodes && $media !== '' && strtolower($media) !== 'all') {
                $nodes = [[self::OUT_GROUP, '@media ' . $media, $nodes]];
            }
            array_push($tree, ...$nodes);
        }
        $remaining = [];
        self::countRules($tree, '', $remaining);
        $rules = self::render($tree, '', $remaining);
        if ($rules === '') {
            return '';
        }
        return $this->fonts($rules) . $rules . $this->animations($rules);
    }

    private function walk(array $nodes, AtomSet $atoms): array
    {
        $out = [];
        foreach ($nodes as $node) {
            switch ($node[0]) {
                case Parser::RULE:
                    $selectors = [];
                    foreach ($node[2] as $alt) {
                        if ($this->matches($alt, $atoms)) {
                            $selectors[] = $alt[0];
                        }
                    }
                    if ($selectors) {
                        $out[] = [self::OUT_RULE, implode(',', $selectors) . '{' . $node[1] . '}'];
                    }
                    break;

                case Parser::GROUP:
                    if ($node[1] === 'media' && self::isPrintOnly($node[2])) {
                        break;
                    }
                    $inner = $this->walk($node[3], $atoms);
                    if ($inner) {
                        $out[] = [self::OUT_GROUP, '@' . $node[1] . ($node[2] !== '' ? ' ' . $node[2] : ''), $inner];
                    }
                    break;

                case Parser::AT:
                    if ($node[1] === 'font-face') {
                        $this->fontFaces[] = $node[3];
                    } elseif (str_ends_with($node[1], 'keyframes')) {
                        $this->keyframes[] = [$node[1], $node[2], $node[3]];
                    } elseif (isset(self::KEEP_AT[$node[1]])) {
                        $out[] = [self::OUT_TEXT, '@' . $node[1] . ' ' . $node[2] . '{' . $node[3] . '}'];
                    }
                    break;

                case Parser::STATEMENT:
                    if ($node[1] === 'layer') {
                        $out[] = [self::OUT_TEXT, '@layer ' . $node[2] . ';'];
                    }
                    break;
            }
        }
        return $out;
    }

    /** Сколько раз встречается каждое правило в своём контексте (цепочке обёрток @media/@supports...). */
    private static function countRules(array $nodes, string $context, array &$counts): void
    {
        foreach ($nodes as $node) {
            if ($node[0] === self::OUT_RULE) {
                $key = $context . $node[1];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            } elseif ($node[0] === self::OUT_GROUP) {
                self::countRules($node[2], $context . $node[1] . '{', $counts);
            }
        }
    }

    /** Вывод без повторов: из одинаковых правил остаётся последнее; опустевшие обёртки не выводятся. */
    private static function render(array $nodes, string $context, array &$remaining): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if ($node[0] === self::OUT_RULE) {
                if (--$remaining[$context . $node[1]] === 0) {
                    $out .= $node[1];
                }
            } elseif ($node[0] === self::OUT_GROUP) {
                $inner = self::render($node[2], $context . $node[1] . '{', $remaining);
                if ($inner !== '') {
                    $out .= $node[1] . '{' . $inner . '}';
                }
            } else {
                $out .= $node[1];
            }
        }
        return $out;
    }

    private function matches(array $alt, AtomSet $atoms): bool
    {
        if ($alt[4] && $this->dropInteraction) {
            return false;
        }
        foreach ($alt[1] as $class) {
            if (!isset($atoms->classes[$class])) {
                return false;
            }
        }
        foreach ($alt[2] as $id) {
            if (!isset($atoms->ids[$id])) {
                return false;
            }
        }
        foreach ($alt[3] as $tag) {
            if (!isset($atoms->tags[$tag])) {
                return false;
            }
        }
        return true;
    }

    public static function isPrintOnly(string $mediaQueryList): bool
    {
        foreach (explode(',', strtolower($mediaQueryList)) as $query) {
            if (!preg_match('/^\s*(?:only\s+)?print\b/', $query)) {
                return false;
            }
        }
        return true;
    }

    private function fonts(string $rules): string
    {
        if ($this->fontFaceMode === 'none' || !$this->fontFaces) {
            return '';
        }
        $used = $this->fontFaceMode === 'used' ? self::usedFamilies($rules) : null;
        $out = '';
        foreach ($this->fontFaces as $body) {
            if ($used !== null) {
                $family = preg_match('/font-family\s*:\s*(["\']?)([^;"\'}]+)\1/i', $body, $m) ? strtolower(trim($m[2])) : '';
                if (!isset($used[$family])) {
                    continue;
                }
            }
            if ($this->fontDisplaySwap && stripos($body, 'font-display') === false) {
                $body .= ';font-display:swap';
            }
            $out .= '@font-face{' . $body . '}';
        }
        return $out;
    }

    /**
     * Семейства шрифтов из font-family/font отобранных правил. null — определить нельзя (font-family через
     * var() с неизвестным значением), тогда @font-face включаются все.
     * @return array<string, true>|null
     */
    public static function usedFamilies(string $css): ?array
    {
        $families = [];
        $viaVariable = false;
        if (preg_match_all('/(?<![\w-])font(-family)?\s*:\s*([^;{}]+)/i', $css, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $value = $m[2];
                if (stripos($value, 'var(') !== false) {
                    $viaVariable = true;
                }
                if ($m[1] === '') {
                    // шорткат font: [style] [weight] size[/line-height] family, ... — размер с единицей
                    // измерения или ключевым словом (700 — это вес, не размер)
                    if (!preg_match('#(?:^|\s)(?:[\d.]+(?:px|r?em|%|pt|pc|vw|vh|vmin|vmax|ex|ch|q|mm|cm|in)|(?:xx?-)?(?:small|large)|medium|smaller|larger)(?:\s*/\s*\S+)?\s+(.+)$#i', trim($value), $mm)) {
                        continue;
                    }
                    $value = $mm[1];
                }
                self::addFamilies($value, $families);
            }
        }
        if ($viaVariable) {
            // значения пользовательских свойств: --font-main: "Manrope", sans-serif
            if (preg_match_all('/--[\w-]+\s*:\s*([^;{}]+)/', $css, $vars)) {
                foreach ($vars[1] as $value) {
                    self::addFamilies($value, $families);
                }
            } else {
                return null;
            }
        }
        return $families;
    }

    private static function addFamilies(string $value, array &$families): void
    {
        foreach (explode(',', str_ireplace('!important', '', $value)) as $family) {
            $family = strtolower(trim($family, " \t\n\r\"'"));
            if ($family !== '') {
                $families[$family] = true;
            }
        }
    }

    private function animations(string $rules): string
    {
        if (!$this->keyframes || !preg_match_all('/animation(?:-name)?\s*:\s*([^;{}]+)/i', $rules, $all)) {
            return '';
        }
        $names = [];
        foreach ($all[1] as $value) {
            foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $token) {
                $names[trim($token, '"\'')] = true;
            }
        }
        $out = '';
        foreach ($this->keyframes as [$atName, $name, $body]) {
            if (isset($names[trim($name, '"\' ')])) {
                $out .= '@' . $atName . ' ' . $name . '{' . $body . '}';
            }
        }
        return $out;
    }
}
