<?php

namespace Vspace\PageSpeed\Html;

/**
 * Набор правок исходного HTML по позициям: замены диапазонов и вставки. Процессоры работают с неизменной
 * строкой Document и только складывают сюда правки; итог собирается одним проходом в apply().
 *
 * Каждый процессор пишет в свой экземпляр, а в общий набор он вливается только при успешном завершении
 * (merge) — так ошибка на середине работы процессора не оставит страницу в полуизменённом виде
 * (например, CSS уже асинхронный, а critical ещё не вставлен).
 */
final class Edits
{
    /** @var list<array{0:int,1:int,2:string,3:int}> [начало, конец, текст, порядковый номер] */
    private array $items = [];
    private int $sequence = 0;

    public function replace(int $start, int $end, string $text): void
    {
        $this->items[] = [$start, $end, $text, $this->sequence++];
    }

    public function insert(int $position, string $text): void
    {
        $this->replace($position, $position, $text);
    }

    public function merge(self $other): void
    {
        foreach ($other->items as $item) {
            $this->replace($item[0], $item[1], $item[2]);
        }
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Вставки в одну позицию выводятся в порядке добавления и раньше замены, начинающейся там же.
     * Правка, пересекающаяся с уже применённой, пропускается.
     */
    public function apply(string $html): string
    {
        if ($this->items === []) {
            return $html;
        }
        $items = $this->items;
        usort($items, static fn(array $a, array $b): int => [$a[0], $a[1] > $a[0], $a[3]] <=> [$b[0], $b[1] > $b[0], $b[3]]);

        $result = '';
        $cursor = 0;
        foreach ($items as [$start, $end, $text]) {
            if ($start < $cursor) {
                continue;
            }
            $result .= substr($html, $cursor, $start - $cursor) . $text;
            $cursor = $end;
        }
        return $result . substr($html, $cursor);
    }
}
