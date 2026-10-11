<?php

namespace Vspace\PageSpeed\Css;

/**
 * Разбирает селектор на требования к разметке: какие классы, id и теги должны быть на странице, чтобы
 * правило могло к чему-то примениться.
 *
 * Результат — надмножество: если требования выполнены, правило МОЖЕТ сработать (взаимное расположение
 * элементов не проверяется), если нет — точно не сработает. Поэтому всё, что нельзя проверить по набору
 * атомов, отбрасывается в сторону «подходит»:
 *  - функциональные псевдоклассы целиком (:not(.x), :is(...), :has(...), :nth-child(2n+1));
 *  - атрибутные селекторы [type="text"];
 *  - псевдоэлементы и прочие псевдоклассы (::before, :first-child, :checked).
 * Отдельно помечаются селекторы с псевдоклассами взаимодействия (:hover, :focus, :active, :visited...) —
 * до первого действия пользователя они не нужны, и critical CSS их по умолчанию не включает.
 *
 * Имена классов раскодируются по правилам CSS: .uk-width-1-2\@m -> «uk-width-1-2@m», .\31 0 -> «10».
 */
final class SelectorAnalyzer
{
    private const INTERACTION = [
        'hover' => true, 'focus' => true, 'focus-within' => true, 'focus-visible' => true, 'active' => true,
        'visited' => true,
    ];
    /** Теги, которые браузер создаёт сам, даже если их нет в разметке. */
    private const IMPLICIT_TAGS = ['html' => true, 'head' => true, 'body' => true, 'tbody' => true];
    private const NAME_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

    /**
     * @return list<array{0:string, 1:list<string>, 2:list<string>, 3:list<string>, 4:bool}>
     *         [текст селектора, классы, id, теги, только при взаимодействии]
     */
    public function analyzeList(string $selectorList): array
    {
        $result = [];
        foreach ($this->splitList($selectorList) as $selector) {
            $result[] = $this->analyze($selector);
        }
        return $result;
    }

    /** @return array{0:string, 1:list<string>, 2:list<string>, 3:list<string>, 4:bool} */
    public function analyze(string $selector): array
    {
        $selector = trim($selector);
        [$simple, $interaction] = $this->strip($selector);

        $classes = $ids = $tags = [];
        $n = strlen($simple);
        $i = 0;
        $expectType = true; // начало составного селектора: здесь может стоять имя тега
        $lastTag = null;
        while ($i < $n) {
            $ch = $simple[$i];
            if ($ch === '.' || $ch === '#') {
                [$ident, $i] = $this->readIdent($simple, $i + 1);
                if ($ident !== '') {
                    if ($ch === '.') {
                        $classes[$ident] = true;
                    } else {
                        $ids[$ident] = true;
                    }
                }
                $expectType = false;
            } elseif ($ch === ' ' || $ch === '>' || $ch === '+' || $ch === '~' || $ch === "\t" || $ch === "\n") {
                $expectType = true;
                $i++;
            } elseif ($ch === '|') {
                // пространство имён: svg|circle, *|a — предыдущее «имя тега» было префиксом
                if ($lastTag !== null) {
                    unset($tags[$lastTag]);
                }
                $expectType = true;
                $i++;
            } elseif ($expectType && ($ch === '\\' || $ch === '_' || ctype_alpha($ch) || ord($ch) >= 0x80)) {
                [$ident, $i] = $this->readIdent($simple, $i);
                $lastTag = strtolower($ident);
                if ($lastTag !== '' && !isset(self::IMPLICIT_TAGS[$lastTag])) {
                    $tags[$lastTag] = true;
                }
                $expectType = false;
            } else {
                $expectType = false; // *, &, остатки
                $i++;
            }
        }
        return [$selector, self::names($classes), self::names($ids), self::names($tags), $interaction];
    }

    /** Ключи как строки: имя класса «10» в ключе массива становится числом. @return list<string> */
    private static function names(array $set): array
    {
        $names = [];
        foreach ($set as $name => $_) {
            $names[] = (string)$name;
        }
        return $names;
    }

    /**
     * Делит список селекторов по запятым верхнего уровня.
     * @return list<string>
     */
    public function splitList(string $list): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $n = strlen($list);
        for ($i = 0; $i < $n; $i++) {
            $ch = $list[$i];
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === '"' || $ch === "'") {
                $i = $this->skipQuoted($list, $i) - 1;
            } elseif ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif (($ch === ')' || $ch === ']') && $depth > 0) {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = substr($list, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($list, $start);

        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }
        return $result;
    }

    /**
     * Убирает из селектора всё, что не проверяется по атомам, и определяет, нужен ли он только при взаимодействии.
     * @return array{0:string, 1:bool}
     */
    private function strip(string $s): array
    {
        $out = '';
        $interaction = false;
        $n = strlen($s);
        $i = 0;
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $out .= substr($s, $i, 2);
                $i += 2;
            } elseif ($ch === '"' || $ch === "'") {
                $i = $this->skipQuoted($s, $i);
            } elseif ($ch === '[') {
                $i = $this->skipBalanced($s, $i, '[', ']');
            } elseif ($ch === ':') {
                $j = $i + 1;
                $isElement = $j < $n && $s[$j] === ':';
                if ($isElement) {
                    $j++;
                }
                $length = strspn($s, self::NAME_CHARS, $j);
                $name = strtolower(substr($s, $j, $length));
                $j += $length;
                if ($j < $n && $s[$j] === '(') {
                    $j = $this->skipBalanced($s, $j, '(', ')');
                } elseif (!$isElement && isset(self::INTERACTION[$name])) {
                    $interaction = true;
                }
                $i = $j;
            } else {
                $out .= $ch;
                $i++;
            }
        }
        return [$out, $interaction];
    }

    /**
     * Идентификатор CSS с раскодированием экранирования.
     * @return array{0:string, 1:int} имя и позиция после него
     */
    private function readIdent(string $s, int $i): array
    {
        $n = strlen($s);
        $ident = '';
        while ($i < $n) {
            $ch = $s[$i];
            if ($ch === '\\') {
                if ($i + 1 >= $n) {
                    $i++;
                    break;
                }
                $hexLength = strspn($s, '0123456789abcdefABCDEF', $i + 1, 6);
                if ($hexLength > 0) {
                    $code = hexdec(substr($s, $i + 1, $hexLength));
                    $ident .= ($code > 0 && $code <= 0x10FFFF) ? mb_chr($code, 'UTF-8') : "\u{FFFD}";
                    $i += 1 + $hexLength;
                    if ($i < $n && ($s[$i] === ' ' || $s[$i] === "\t" || $s[$i] === "\n")) {
                        $i++; // пробел-терминатор шестнадцатеричного кода
                    }
                } else {
                    $ident .= $s[$i + 1];
                    $i += 2;
                }
            } elseif ($ch === '-' || $ch === '_' || ctype_alnum($ch) || ord($ch) >= 0x80) {
                $ident .= $ch;
                $i++;
            } else {
                break;
            }
        }
        return [$ident, $i];
    }

    private function skipQuoted(string $s, int $i): int
    {
        $quote = $s[$i];
        $n = strlen($s);
        for ($i++; $i < $n; $i++) {
            if ($s[$i] === '\\') {
                $i++;
            } elseif ($s[$i] === $quote) {
                return $i + 1;
            }
        }
        return $n;
    }

    private function skipBalanced(string $s, int $i, string $open, string $close): int
    {
        $depth = 0;
        $n = strlen($s);
        for (; $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch === '\\') {
                $i++;
            } elseif ($ch === '"' || $ch === "'") {
                $i = $this->skipQuoted($s, $i) - 1;
            } elseif ($ch === $open) {
                $depth++;
            } elseif ($ch === $close && --$depth === 0) {
                return $i + 1;
            }
        }
        return $n;
    }
}
