<?php

namespace App\Domain\Book;

use InvalidArgumentException;
use Stringable;

final readonly class Isbn implements Stringable
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[0-9]{13}$/D', $value)) {
            throw new InvalidArgumentException('El ISBN debe contener exactamente 13 dígitos.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
