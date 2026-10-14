<?php

namespace App\Domain\Loan;

use DateTimeImmutable;

final class LoanPolicy
{
    public const MAX_UNRETURNED_BOOKS = 3;

    public const TERM_DAYS = 14;

    public static function dueAt(DateTimeImmutable $loanedAt): DateTimeImmutable
    {
        return $loanedAt->modify('+'.self::TERM_DAYS.' days');
    }
}
