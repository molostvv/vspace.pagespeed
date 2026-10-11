<?php

namespace Vspace\PageSpeed\Css;

/**
 * Разбор CSS в дерево правил верхнего уровня — без внешних библиотек.
 *
 * Узлы (массивы, чтобы индекс компактно кешировался через var_export):
 *   [RULE, селектор, тело]                       — обычное правило
 *   [GROUP, имя, условие, дочерние узлы]          — @media/@supports/@layer {...}/@container...
 *   [AT, имя, прелюдия, тело]                     — @font-face, @keyframes, @page... (тело не разбирается)
 *   [STATEMENT, имя, прелюдия]                    — @import, @charset, @layer a, b;
 *
 * Сканер учитывает строки, комментарии, экранирование и скобки: «;» внутри url(data:...;...) не обрывает
 * правило, «{» в строке не открывает блок. Тела правил не разбираются на декларации — для critical CSS
 * важен только селектор. Битый CSS восстанавливается как в браузере: мусор до «;» или «}» пропускается.
 */
final class Parser
{
    public const RULE = 0;
    public const GROUP = 1;
    public const AT = 2;
    public const STATEMENT = 3;

    /** @-правила, внутри которых обычные правила (разбираются рекурсивно). */
    private const GROUP_RULES = [
        'media' => true, 'supports' => true, 'layer' => true, 'container' => true, 'document' => true,
        '-moz-document' => true, 'scope' => true, 'starting-style' => true,
    ];
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

    private string $css = '';

    /** @return list<array> */
    public function parse(string $css): array
    {
        if (str_starts_with($css, "\xEF\xBB\xBF")) {
            $css = substr($css, 3);
        }
        $this->css = $css;
        $pos = 0;
        $nodes = $this->parseList($pos, strlen($css));
        $this->css = '';
        return $nodes;
    }

    private function parseList(int &$pos, int $end): array
    {
        $css = $this->css;
        $nodes = [];
        while (true) {
            $pos = $this->skipWhitespaceAndComments($pos, $end);
            if ($pos >= $end) {
                break;
            }
            $ch = $css[$pos];
            if ($ch === '}' || $ch === ';') {
                $pos++;
                continue;
            }
            if ($ch === '<' && substr_compare($css, '<!--', $pos, 4) === 0) {
                $pos += 4;
                continue;
            }
            if ($ch === '-' && substr_compare($css, '-->', $pos, 3) === 0) {
                $pos += 3;
                continue;
            }
            if ($ch === '@') {
                $node = $this->parseAtRule($pos, $end);
                if ($node !== null) {
                    $nodes[] = $node;
                }
                continue;
            }

            [$stop, $stopChar] = $this->scanPrelude($pos, $end);
            if ($stopChar !== '{') {
                $pos = $stop + 1;
                continue;
            }
            $close = $this->findBlockEnd($stop + 1, $end);
            $selector = self::clean(substr($css, $pos, $stop - $pos));
            if ($selector !== '') {
                $nodes[] = [self::RULE, $selector, substr($css, $stop + 1, $close - $stop - 1)];
            }
            $pos = $close + 1;
        }
        return $nodes;
    }

    private function parseAtRule(int &$pos, int $end): ?array
    {
        $css = $this->css;
        $nameStart = $pos + 1;
        $nameLength = strspn($css, self::NAME_CHARS, $nameStart, max(0, $end - $nameStart));
        $name = strtolower(substr($css, $nameStart, $nameLength));
        $preludeStart = $nameStart + $nameLength;

        [$stop, $stopChar] = $this->scanPrelude($preludeStart, $end);
        $prelude = self::clean(substr($css, $preludeStart, $stop - $preludeStart));
        if ($stopChar !== '{') {
            $pos = $stop + 1;
            return $name === '' ? null : [self::STATEMENT, $name, $prelude];
        }

        $close = $this->findBlockEnd($stop + 1, $end);
        if (isset(self::GROUP_RULES[$name])) {
            $inner = $stop + 1;
            $node = [self::GROUP, $name, $prelude, $this->parseList($inner, $close)];
        } else {
            $node = [self::AT, $name, $prelude, substr($css, $stop + 1, $close - $stop - 1)];
        }
        $pos = $close + 1;
        return $name === '' ? null : $node;
    }

    /**
     * Ищет конец прелюдии — «{» или «;» вне скобок, строк и комментариев.
     * @return array{0:int, 1:string} позиция и найденный символ ('' — дошли до конца)
     */
    private function scanPrelude(int $pos, int $end): array
    {
        $css = $this->css;
        $depth = 0;
        while ($pos < $end) {
            $pos += strcspn($css, "{;\"'/\\()[]", $pos, $end - $pos);
            if ($pos >= $end) {
                break;
            }
            switch ($css[$pos]) {
                case '{':
                case ';':
                    if ($depth === 0) {
                        return [$pos, $css[$pos]];
                    }
                    $pos++;
                    break;
                case '"':
                case "'":
                    $pos = $this->skipString($pos, $end);
                    break;
                case '/':
                    $pos = ($pos + 1 < $end && $css[$pos + 1] === '*') ? $this->skipComment($pos, $end) : $pos + 1;
                    break;
                case '\\':
                    $pos += 2;
                    break;
                case '(':
                case '[':
                    $depth++;
                    $pos++;
                    break;
                default: // ')' и ']'
                    if ($depth > 0) {
                        $depth--;
                    }
                    $pos++;
            }
        }
        return [$end, ''];
    }

    /** Позиция «}», закрывающей блок, который начинается в $pos (сразу после «{»); $end — если не закрыт. */
    private function findBlockEnd(int $pos, int $end): int
    {
        $css = $this->css;
        $depth = 1;
        while ($pos < $end) {
            $pos += strcspn($css, "{}\"'/\\", $pos, $end - $pos);
            if ($pos >= $end) {
                break;
            }
            $ch = $css[$pos];
            if ($ch === '{') {
                $depth++;
                $pos++;
            } elseif ($ch === '}') {
                if (--$depth === 0) {
                    return $pos;
                }
                $pos++;
            } elseif ($ch === '"' || $ch === "'") {
                $pos = $this->skipString($pos, $end);
            } elseif ($ch === '/') {
                $pos = ($pos + 1 < $end && $css[$pos + 1] === '*') ? $this->skipComment($pos, $end) : $pos + 1;
            } else {
                $pos += 2;
            }
        }
        return $end;
    }

    private function skipString(int $pos, int $end): int
    {
        $css = $this->css;
        $quote = $css[$pos];
        $pos++;
        while ($pos < $end) {
            $pos += strcspn($css, $quote . "\\\n", $pos, $end - $pos);
            if ($pos >= $end) {
                return $end;
            }
            $ch = $css[$pos];
            if ($ch === $quote) {
                return $pos + 1;
            }
            if ($ch === "\n") {
                return $pos; // незакрытая строка заканчивается на переводе строки
            }
            $pos += 2;
        }
        return $end;
    }

    private function skipComment(int $pos, int $end): int
    {
        $close = strpos($this->css, '*/', $pos + 2);
        return $close === false || $close + 2 > $end ? $end : $close + 2;
    }

    private function skipWhitespaceAndComments(int $pos, int $end): int
    {
        while ($pos < $end) {
            $pos += strspn($this->css, " \t\n\r\f", $pos, $end - $pos);
            if ($pos + 1 < $end && $this->css[$pos] === '/' && $this->css[$pos + 1] === '*') {
                $pos = $this->skipComment($pos, $end);
            } else {
                break;
            }
        }
        return $pos;
    }

    /** Селектор/прелюдия без комментариев, с одиночными пробелами. */
    private static function clean(string $text): string
    {
        if (str_contains($text, '/*')) {
            $text = preg_replace('#/\*.*?\*/#s', '', $text);
        }
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
