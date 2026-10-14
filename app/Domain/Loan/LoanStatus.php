<?php

namespace App\Domain\Loan;

use DateTimeImmutable;
use InvalidArgumentException;

enum LoanStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Closed = 'closed';

    public static function calculate(array $items, DateTimeImmutable $now): self
    {
        if (count($items) < 1 || count($items) > LoanPolicy::MAX_UNRETURNED_BOOKS) {
            throw new InvalidArgumentException('Un préstamo debe contener de uno a tres libros.');
        }

        $hasOpenItems = false;
        foreach ($items as $item) {
            if ($item['returned_at'] !== null) {
                continue;
            }
            $hasOpenItems = true;
            if ($item['due_at'] < $now) {
                return self::Overdue;
            }
        }

        return $hasOpenItems ? self::Open : self::Closed;
    }
}
