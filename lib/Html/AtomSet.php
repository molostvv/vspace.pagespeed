<?php

namespace Vspace\PageSpeed\Html;

/**
 * Набор «атомов» разметки — классов, id и имён тегов, — по которому решается, нужно ли CSS-правило.
 * Хранится как ключи массивов (проверка isset за O(1)).
 */
final class AtomSet
{
    /** @var array<string, true> */
    public array $classes = [];
    /** @var array<string, true> */
    public array $ids = [];
    /** @var array<string, true> */
    public array $tags = [];

    public function addClass(string $class): void
    {
        $this->classes[$class] = true;
    }

    public function addId(string $id): void
    {
        $this->ids[$id] = true;
    }

    public function addTag(string $tag): void
    {
        $this->tags[strtolower($tag)] = true;
    }

    /** Подмножество атомов, которые встречаются в CSS (остальные на результат не влияют). */
    public function intersect(array $classes, array $ids, array $tags): self
    {
        $result = new self();
        $result->classes = array_intersect_key($this->classes, $classes);
        $result->ids = array_intersect_key($this->ids, $ids);
        $result->tags = array_intersect_key($this->tags, $tags);
        return $result;
    }

    public function signature(): string
    {
        $parts = [];
        foreach ([$this->classes, $this->ids, $this->tags] as $set) {
            $keys = array_keys($set);
            sort($keys, SORT_STRING);
            $parts[] = implode(' ', $keys);
        }
        return md5(implode("\n", $parts));
    }

    public function count(): int
    {
        return count($this->classes) + count($this->ids) + count($this->tags);
    }
}
