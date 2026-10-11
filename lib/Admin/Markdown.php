<?php

namespace Vspace\PageSpeed\Admin;

/**
 * Markdown -> HTML для страницы инструкции в админке (README.md и docs/*.md модуля). Только то, что есть в
 * этих файлах: заголовки (с якорем {#id}), абзацы, списки (без вложенности), таблицы, блоки кода, `код`,
 * **жирный**, *курсив*, [ссылки](...) и экранирование \*. Сырой HTML не пропускается — всё экранируется.
 */
final class Markdown
{
    /** @var callable(string): string */
    private $linkResolver;

    /** @param callable(string): string|null $linkResolver адрес ссылки -> итоговый href (относительные .md -> страница инструкции) */
    public function __construct(?callable $linkResolver = null)
    {
        $this->linkResolver = $linkResolver ?? static fn(string $url): string => $url;
    }

    public function toHtml(string $markdown): string
    {
        // не \R: без флага u он считает переводом строки байт 0x85 — второй байт буквы «х» в UTF-8
        $lines = preg_split('/\r\n|\r|\n/', $markdown);
        $count = count($lines);
        $html = [];

        for ($i = 0; $i < $count;) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;
                continue;
            }

            if (str_starts_with($line, '```')) {
                $code = [];
                for ($i++; $i < $count && !str_starts_with($lines[$i], '```'); $i++) {
                    $code[] = $lines[$i];
                }
                $i++;
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
                $level = strlen($m[1]);
                // якорь для ссылок из настроек: «## WebP {#webp}» -> <h2 id="webp">
                $id = '';
                if (preg_match('/^(.*?)\s*\{#([a-z0-9_-]+)\}$/i', $m[2], $a)) {
                    $m[2] = $a[1];
                    $id = ' id="' . $a[2] . '"';
                }
                $html[] = '<h' . $level . $id . '>' . $this->inline($m[2]) . '</h' . $level . '>';
                $i++;
                continue;
            }

            if (preg_match('/^(?:-{3,}|\*{3,})\s*$/', $line)) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            if (str_starts_with($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|[\s:|-]+\|?\s*$/', $lines[$i + 1])) {
                $html[] = $this->table($lines, $i);
                continue;
            }

            if (preg_match('/^([-*+]|\d+\.)\s+/', $line, $m)) {
                $html[] = $this->listBlock($lines, $i, ctype_digit($m[1][0]));
                continue;
            }

            // первая строка — всегда текст абзаца (например, «|» без строки-разделителя таблицы)
            $paragraph = [trim($line)];
            for ($i++; $i < $count && trim($lines[$i]) !== '' && !$this->startsBlock($lines, $i); $i++) {
                $paragraph[] = trim($lines[$i]);
            }
            $html[] = '<p>' . $this->inline(implode(' ', $paragraph)) . '</p>';
        }

        return implode("\n", $html);
    }

    /** Строка начинает другой блок — абзац на ней заканчивается. */
    private function startsBlock(array $lines, int $i): bool
    {
        $line = $lines[$i];
        return str_starts_with($line, '```')
            || preg_match('/^(#{1,6}\s|[-*+]\s|\d+\.\s|\|)/', $line) === 1;
    }

    private function table(array $lines, int &$i): string
    {
        $head = $this->cells($lines[$i]);
        $i += 2;
        $html = '<table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        while (isset($lines[$i]) && str_starts_with($lines[$i], '|')) {
            $html .= '<tr>';
            foreach ($this->cells($lines[$i]) as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
            $i++;
        }
        return $html . '</tbody></table>';
    }

    /** Ячейки строки таблицы; «|» внутри `кода` и экранированный \| ячейку не делят. */
    private function cells(string $line): array
    {
        $line = trim($line);
        $line = substr($line, 1, str_ends_with($line, '|') ? -1 : null);
        $cells = [];
        $cell = '';
        $inCode = false;
        $length = strlen($line);
        for ($k = 0; $k < $length; $k++) {
            $char = $line[$k];
            if ($char === '\\' && ($line[$k + 1] ?? '') === '|') {
                $cell .= '\\|';
                $k++;
            } elseif ($char === '|' && !$inCode) {
                $cells[] = trim($cell);
                $cell = '';
            } else {
                if ($char === '`') {
                    $inCode = !$inCode;
                }
                $cell .= $char;
            }
        }
        $cells[] = trim($cell);
        return $cells;
    }

    /** Список; строки с отступом — продолжение пункта. */
    private function listBlock(array $lines, int &$i, bool $ordered): string
    {
        $marker = $ordered ? '/^\d+\.\s+/' : '/^[-*+]\s+/';
        $items = [];
        while (isset($lines[$i]) && preg_match($marker, $lines[$i], $m)) {
            $item = substr($lines[$i], strlen($m[0]));
            for ($i++; isset($lines[$i]) && preg_match('/^\s+\S/', $lines[$i]); $i++) {
                $item .= ' ' . trim($lines[$i]);
            }
            $items[] = '<li>' . $this->inline($item) . '</li>';
        }
        $tag = $ordered ? 'ol' : 'ul';
        return '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
    }

    private function inline(string $text): string
    {
        // код и экранированные символы — в заглушки, чтобы разметка внутри них не разбиралась
        $stash = [];
        $text = preg_replace_callback('/`([^`]+)`|\\\\([\\\\`*_{}\[\]()#+\-.!|<>])/', static function (array $m) use (&$stash): string {
            $stash[] = isset($m[2]) ? self::escape($m[2]) : '<code>' . self::escape($m[1]) . '</code>';
            return "\x01" . (count($stash) - 1) . "\x02";
        }, $text);

        $text = self::escape($text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function (array $m): string {
            $href = ($this->linkResolver)(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/^\s*(?:javascript|data|vbscript):/i', $href)) {
                return $m[1];
            }
            $external = preg_match('~^(?:https?:)?//~i', $href) === 1;
            return '<a href="' . self::escape($href) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . $m[1] . '</a>';
        }, $text);
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![*\w])\*(?=\S)([^*]+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $text);

        return preg_replace_callback("/\x01(\d+)\x02/", static fn(array $m): string => $stash[(int)$m[1]], $text);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
