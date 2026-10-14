<?php

namespace App\Domain\Book;

use InvalidArgumentException;

final readonly class Quantity
{
    public function __construct(public int $value)
    {
        if ($value < 1) {
            throw new InvalidArgumentException('La cantidad total debe ser mayor que cero.');
        }
    }
}
