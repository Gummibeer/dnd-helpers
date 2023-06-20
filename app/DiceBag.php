<?php

namespace App;

use Illuminate\Support\Str;

class DiceBag
{
    public static function roll(string $dices): int
    {
        return Str::of($dices)
            ->explode('-')
            ->map(fn (string $dices): int => \DiceBag\DiceBag::factory($dices)->getTotal())
            ->reduce(fn (?int $result, int $value): int => $result !== null ? $result - $value : $value);
    }
}
