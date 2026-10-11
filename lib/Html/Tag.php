<?php

namespace Vspace\PageSpeed\Html;

/**
 * Открывающий тег с атрибутами: разбор, правка, обратная сборка.
 *
 * Нетронутые атрибуты при сборке выводятся байт в байт как в исходнике (кавычки, сущности, порядок),
 * изменённые — в двойных кавычках с экранированием.
 */
final class Tag
{
    private const ATTR_PATTERN = '#([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#';

    /** @var array<string, array{name:string, raw:?string, value:?string}> ключ — имя в нижнем регистре */
    private array $attributes = [];
    private bool $selfClosing = false;

    private function __construct(public readonly string $name)
    {
    }

    /** @param string $html текст тега целиком: <link rel="stylesheet" href="..."> */
    public static function parse(string $html): ?self
    {
        if (!preg_match('#^<([a-zA-Z][a-zA-Z0-9:-]*)#', $html, $m)) {
            return null;
        }
        $tag = new self(strtolower($m[1]));
        $attrs = substr($html, strlen($m[0]), -1);
        if (str_ends_with(rtrim($attrs), '/')) {
            $tag->selfClosing = true;
            $attrs = substr(rtrim($attrs), 0, -1);
        }
        if (preg_match_all(self::ATTR_PATTERN, $attrs, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            foreach ($all as $a) {
                $key = strtolower($a[1]);
                if (isset($tag->attributes[$key])) {
                    continue; // как в браузере: действует первое вхождение
                }
                $value = $a[2] ?? $a[3] ?? $a[4];
                $tag->attributes[$key] = [
                    'name' => $a[1],
                    'raw' => $a[0],
                    'value' => $value === null ? null : self::decode($value),
                ];
            }
        }
        return $tag;
    }

    /**
     * Значение атрибута прямо из строки атрибутов тега (без полного разбора) — для массового сканирования.
     * @param string $attrs часть тега после имени
     */
    public static function attrFromString(string $attrs, string $name): ?string
    {
        if (stripos($attrs, $name) === false) {
            return null;
        }
        $pattern = '#(?:^|\s)' . preg_quote($name, '#') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))#i';
        if (!preg_match($pattern, $attrs, $m, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        return self::decode($m[1] ?? $m[2] ?? $m[3] ?? '');
    }

    /** @return list<string> */
    public static function splitClasses(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }

    public function has(string $name): bool
    {
        return isset($this->attributes[strtolower($name)]);
    }

    /** Значение атрибута (сущности раскодированы); '' — для атрибута без значения, null — если атрибута нет. */
    public function get(string $name): ?string
    {
        $attr = $this->attributes[strtolower($name)] ?? null;
        return $attr === null ? null : ($attr['value'] ?? '');
    }

    /** @param string|null $value null — булев атрибут без значения (async, defer) */
    public function set(string $name, ?string $value): self
    {
        $key = strtolower($name);
        $this->attributes[$key] = ['name' => $this->attributes[$key]['name'] ?? $name, 'raw' => null, 'value' => $value];
        return $this;
    }

    public function remove(string $name): self
    {
        unset($this->attributes[strtolower($name)]);
        return $this;
    }

    public function toHtml(): string
    {
        $html = '<' . $this->name;
        foreach ($this->attributes as $attr) {
            if ($attr['raw'] !== null) {
                $html .= ' ' . $attr['raw'];
            } elseif ($attr['value'] === null) {
                $html .= ' ' . $attr['name'];
            } else {
                $html .= ' ' . $attr['name'] . '="' . htmlspecialchars($attr['value'], ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"';
            }
        }
        return $html . ($this->selfClosing ? ' />' : '>');
    }

    private static function decode(string $value): string
    {
        return str_contains($value, '&') ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value;
    }
}
