<?php

namespace App\Domain\Book;

use InvalidArgumentException;

final readonly class Inventory
{
    public function __construct(
        public int $total,
        public int $available,
    ) {
        if ($total < 1 || $available < 0 || $available > $total) {
            throw new InvalidArgumentException('El inventario debe estar entre cero y el total.');
        }
    }

    public function withdraw(): self
    {
        if ($this->available === 0) {
            throw new InvalidArgumentException('No quedan ejemplares disponibles.');
        }

        return new self($this->total, $this->available - 1);
    }

    public function restock(): self
    {
        if ($this->available === $this->total) {
            throw new InvalidArgumentException('El inventario disponible no puede superar el total.');
        }

        return new self($this->total, $this->available + 1);
    }

    public function changeTotal(Quantity $total): self
    {
        $available = $this->available + $total->value - $this->total;

        if ($available < 0) {
            throw new InvalidArgumentException('El total no puede ser menor que los ejemplares prestados.');
        }

        return new self($total->value, $available);
    }
}
