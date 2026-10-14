<?php

namespace Tests\Unit;

use App\Domain\Book\Inventory;
use App\Domain\Book\Isbn;
use App\Domain\Book\Quantity;
use App\Domain\Loan\LoanPolicy;
use App\Domain\Loan\LoanStatus;
use App\Domain\Shared\DateRange;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LibraryDomainTest extends TestCase
{
    public function test_isbn_requires_thirteen_ascii_digits(): void
    {
        self::assertSame('9780307474728', (new Isbn('9780307474728'))->value);
        $this->expectException(InvalidArgumentException::class);
        new Isbn('978030747472');
    }

    public function test_quantity_must_be_positive(): void
    {
        self::assertSame(1, (new Quantity(1))->value);
        $this->expectException(InvalidArgumentException::class);
        new Quantity(0);
    }

    public function test_inventory_withdraws_and_restocks_within_bounds(): void
    {
        $inventory = new Inventory(1, 1);
        self::assertSame(0, $inventory->withdraw()->available);
        self::assertSame(1, $inventory->withdraw()->restock()->available);
        $this->expectException(InvalidArgumentException::class);
        (new Inventory(1, 0))->withdraw();
    }

    public function test_inventory_total_change_preserves_number_of_loaned_copies(): void
    {
        $inventory = (new Inventory(4, 2))->changeTotal(new Quantity(5));
        self::assertSame([5, 3], [$inventory->total, $inventory->available]);

        $this->expectException(InvalidArgumentException::class);
        (new Inventory(4, 1))->changeTotal(new Quantity(2));
    }

    public function test_date_range_accepts_equal_endpoints_and_rejects_reverse_order(): void
    {
        $date = new DateTimeImmutable('2026-10-14T00:00:00Z');
        self::assertSame($date, (new DateRange($date, $date))->from);

        $this->expectException(InvalidArgumentException::class);
        new DateRange($date->modify('+1 day'), $date);
    }

    public function test_loan_due_date_is_fourteen_days_after_issue(): void
    {
        $issuedAt = new DateTimeImmutable('2026-10-14T14:15:00Z');
        self::assertSame(
            '2026-10-28T14:15:00+00:00',
            LoanPolicy::dueAt($issuedAt)->format(DATE_ATOM),
        );
    }

    public function test_loan_status_is_derived_from_unreturned_items(): void
    {
        $now = new DateTimeImmutable('2026-10-29T00:00:00Z');
        $future = new DateTimeImmutable('2026-10-30T00:00:00Z');
        $past = new DateTimeImmutable('2026-10-28T00:00:00Z');
        self::assertSame(LoanStatus::Open, LoanStatus::calculate([['due_at' => $future, 'returned_at' => null]], $now));
        self::assertSame(LoanStatus::Overdue, LoanStatus::calculate([['due_at' => $past, 'returned_at' => null]], $now));
        self::assertSame(LoanStatus::Closed, LoanStatus::calculate([['due_at' => $past, 'returned_at' => $now]], $now));
    }
}
