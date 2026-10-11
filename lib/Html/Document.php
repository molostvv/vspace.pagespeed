<?php

namespace Vspace\PageSpeed\Html;

/**
 * Исходный HTML страницы и разметка позиций в нём — без DOM-дерева.
 *
 * Модуль не пересобирает страницу через DOMDocument (это медленно и портит разметку: HTML5-теги, кодировку,
 * содержимое скриптов). Вместо этого Document один раз находит:
 *  - границы <head> и <body>;
 *  - «защищённые» области, где теги не разбираются: содержимое script/style/noscript/textarea/title/template
 *    и HTML-комментарии (в т.ч. условные <!--[if IE]>);
 *  - список всех открывающих и закрывающих тегов вне этих областей.
 * Процессоры читают эти позиции и складывают правки в Edits, а итоговая строка собирается одним проходом.
 */
final class Document
{
    /** Элементы, содержимое которых — не разметка. */
    private const RAW_TEXT = ['script', 'style', 'noscript', 'textarea', 'title', 'template'];
    /** Пустые элементы: у них нет закрывающего тега. */
    private const VOID = [
        'area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1,
        'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1,
    ];
    private const TAG_PATTERN = '#<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>#';

    public readonly int $length;
    /** Позиция сразу после «>» открывающего <head>. */
    public readonly int $headOpenEnd;
    /** Позиция «</head>». */
    public readonly int $headEnd;
    /** Позиция «<body». */
    public readonly int $bodyStart;
    /** Позиция сразу после «>» открывающего <body>. */
    public readonly int $bodyOpenEnd;
    /** Позиция «</body>» (или конец документа). */
    public readonly int $bodyEnd;

    /**
     * Сырые области: [начало содержимого, конец содержимого, имя элемента или '#comment', начало тега, конец элемента].
     * Для комментария содержимое = весь комментарий.
     * @var list<array{0:int,1:int,2:string,3:int,4:int}>
     */
    private array $rawRanges = [];
    /** @var array<int, int> позиция открывающего тега raw-элемента => индекс в $rawRanges */
    private array $rawByTagPos = [];
    /**
     * @var list<array{pos:int,end:int,name:string,close:bool,attrs:string}>|null
     */
    private ?array $tags = null;

    private function __construct(public readonly string $html)
    {
        $this->length = strlen($html);
    }

    /** null — если это не HTML-документ с <head> и <body> (фрагмент, JSON, XML и т.п.). */
    public static function fromHtml(string $html): ?self
    {
        $doc = new self($html);
        $doc->scanRawRanges();
        return $doc->locateSections() ? $doc : null;
    }

    /**
     * Все теги документа вне защищённых областей, по порядку.
     * @return list<array{pos:int,end:int,name:string,close:bool,attrs:string}>
     */
    public function tags(): array
    {
        if ($this->tags !== null) {
            return $this->tags;
        }
        $tags = [];
        // Регулярку гоняем по каждому «незащищённому» участку отдельно: иначе «тег», начатый внутри скрипта
        // (if(a<b)...), мог бы захватить кусок разметки за его пределами.
        $segmentStart = 0;
        foreach ($this->rawRanges as $range) {
            $this->collectTags(substr($this->html, $segmentStart, $range[0] - $segmentStart), $segmentStart, $tags);
            $segmentStart = $range[1];
        }
        $this->collectTags(substr($this->html, $segmentStart), $segmentStart, $tags);
        return $this->tags = $tags;
    }

    /** Индекс первого тега, начинающегося не раньше $pos. */
    public function firstTagIndexAt(int $pos): int
    {
        $tags = $this->tags();
        $lo = 0;
        $hi = count($tags);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($tags[$mid]['pos'] < $pos) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Индекс закрывающего тега для открывающего тега с индексом $index (по глубине вложенности одноимённых
     * тегов). Для пустых элементов и самозакрытых тегов — сам $index. Если закрывающего нет — последний тег до </body>.
     */
    public function elementEndIndex(int $index): int
    {
        $tags = $this->tags();
        $open = $tags[$index];
        if ($open['close'] || isset(self::VOID[$open['name']]) || str_ends_with(rtrim($open['attrs']), '/')) {
            return $index;
        }
        $depth = 0;
        $count = count($tags);
        for ($k = $index + 1; $k < $count; $k++) {
            $tag = $tags[$k];
            if ($tag['name'] !== $open['name']) {
                if ($tag['pos'] >= $this->bodyEnd) {
                    return $k - 1;
                }
                continue;
            }
            if (!$tag['close']) {
                $depth++;
            } elseif ($depth-- === 0) {
                return $k;
            }
        }
        return $count - 1;
    }

    /**
     * Сырой элемент (style, script...) по позиции его открывающего тега.
     * @return array{contentStart:int, contentEnd:int, end:int}|null
     */
    public function rawElement(int $tagPos): ?array
    {
        if (!isset($this->rawByTagPos[$tagPos])) {
            return null;
        }
        $range = $this->rawRanges[$this->rawByTagPos[$tagPos]];
        return ['contentStart' => $range[0], 'contentEnd' => $range[1], 'end' => $range[4]];
    }

    private function collectTags(string $segment, int $offset, array &$tags): void
    {
        if ($segment === '' || !str_contains($segment, '<')) {
            return;
        }
        if (!preg_match_all(self::TAG_PATTERN, $segment, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($matches as $m) {
            $tags[] = [
                'pos' => $offset + $m[0][1],
                'end' => $offset + $m[0][1] + strlen($m[0][0]),
                'name' => strtolower($m[2][0]),
                'close' => $m[1][0] === '/',
                'attrs' => $m[3][0],
            ];
        }
    }

    private function scanRawRanges(): void
    {
        $html = $this->html;
        $pattern = '#<!--|<(' . implode('|', self::RAW_TEXT) . ')(?=[\s>/])#i';
        $pos = 0;
        while (preg_match($pattern, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $start = $m[0][1];
            if ($m[0][0] === '<!--') {
                $close = strpos($html, '-->', $start + 4);
                $end = $close === false ? $this->length : $close + 3;
                $this->rawRanges[] = [$start, $end, '#comment', $start, $end];
                $pos = $end;
                continue;
            }
            $name = strtolower($m[1][0]);
            $attrStart = $start + 1 + strlen($name);
            if (!preg_match('#\G(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>#', $html, $mm, 0, $attrStart)) {
                $pos = $start + 1;
                continue;
            }
            $openEnd = $attrStart + strlen($mm[0]);
            $close = $this->findClosingTag($name, $openEnd);
            if ($close === null) {
                $contentEnd = $end = $this->length;
            } else {
                $contentEnd = $close;
                $gt = strpos($html, '>', $close);
                $end = $gt === false ? $this->length : $gt + 1;
            }
            $this->rawByTagPos[$start] = count($this->rawRanges);
            $this->rawRanges[] = [$openEnd, $contentEnd, $name, $start, $end];
            $pos = $end;
        }
    }

    private function findClosingTag(string $name, int $from): ?int
    {
        $needle = '</' . $name;
        $len = strlen($needle);
        while (($pos = stripos($this->html, $needle, $from)) !== false) {
            $next = $this->html[$pos + $len] ?? '>';
            if ($next === '>' || $next === '/' || ctype_space($next)) {
                return $pos;
            }
            $from = $pos + $len;
        }
        return null;
    }

    private function locateSections(): bool
    {
        $headOpenEnd = $headEnd = $bodyStart = $bodyOpenEnd = null;
        $bodyEnd = null;
        foreach ($this->tags() as $tag) {
            if ($tag['name'] === 'head') {
                if (!$tag['close'] && $headOpenEnd === null) {
                    $headOpenEnd = $tag['end'];
                } elseif ($tag['close'] && $headEnd === null) {
                    $headEnd = $tag['pos'];
                }
            } elseif ($tag['name'] === 'body') {
                if (!$tag['close'] && $bodyStart === null) {
                    $bodyStart = $tag['pos'];
                    $bodyOpenEnd = $tag['end'];
                } elseif ($tag['close']) {
                    $bodyEnd = $tag['pos'];
                }
            }
        }
        if ($headOpenEnd === null || $headEnd === null || $bodyStart === null || $bodyStart < $headEnd) {
            return false;
        }
        $this->headOpenEnd = $headOpenEnd;
        $this->headEnd = $headEnd;
        $this->bodyStart = $bodyStart;
        $this->bodyOpenEnd = $bodyOpenEnd;
        $this->bodyEnd = $bodyEnd !== null && $bodyEnd > $bodyOpenEnd ? $bodyEnd : $this->length;
        return true;
    }
}
