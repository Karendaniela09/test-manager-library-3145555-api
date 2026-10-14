<?php

namespace App\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {
        if ($to < $from) {
            throw new InvalidArgumentException('La fecha final debe ser igual o posterior a la inicial.');
        }
    }
}
