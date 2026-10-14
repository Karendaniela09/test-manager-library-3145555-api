<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $rawItems = $request->input('items');
        if (is_array($rawItems)) {
            $ids = array_column($rawItems, 'book_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw new ApiException(
                    'DUPLICATE_BOOK_IN_LOAN',
                    'Un préstamo no puede repetir el mismo libro.',
                    422,
                );
            }
        }
        $data = $request->validate([
            'member_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:3'],
            'items.*.book_id' => ['required', 'uuid'],
        ]);

        $loan = DB::transaction(function () use ($request, $data): object {
            $member = DB::table('library.member')
                ->where('id', $data['member_id'])
                ->lockForUpdate()
                ->first();
            if ($member === null) {
                $this->notFound();
            }

            $activeCount = DB::table('library.loan_item as li')
                ->join('library.loan as l', 'l.id', '=', 'li.loan_id')
                ->where('l.member_id', $member->id)
                ->whereNull('li.returned_at')
                ->count();
            if ($activeCount + count($data['items']) > 3) {
                throw new ApiException(
                    'MEMBER_LIMIT_EXCEEDED',
                    'El socio superaría el límite de tres libros sin devolver.',
                    422,
                    [['field' => 'items', 'issue' => "préstamos activos actuales: $activeCount"]],
                );
            }

            $hasOverdue = DB::table('library.loan_item as li')
                ->join('library.loan as l', 'l.id', '=', 'li.loan_id')
                ->where('l.member_id', $member->id)
                ->whereNull('li.returned_at')
                ->where('li.due_at', '<', now('UTC'))
                ->exists();
            if ($hasOverdue) {
                throw new ApiException(
                    'MEMBER_HAS_OVERDUE',
                    'El socio tiene libros vencidos pendientes de devolución.',
                    409,
                );
            }

            $books = DB::table('library.book as b')
                ->join('library.category as c', 'c.id', '=', 'b.category_id')
                ->whereIn('b.id', array_column($data['items'], 'book_id'))
                ->whereNull('b.deleted_at')
                ->select('b.*', 'c.name as category_name')
                ->get()
                ->keyBy('id');
            if ($books->count() !== count($data['items'])) {
                $this->notFound();
            }

            $now = CarbonImmutable::now('UTC');
            $loanId = (string) Str::uuid();
            DB::table('library.loan')->insert([
                'id' => $loanId,
                'member_id' => $member->id,
                'loaned_by_user_id' => $request->attributes->get('library_user')->id,
                'loaned_at' => $now,
            ]);

            foreach ($data['items'] as $item) {
                $book = $books[$item['book_id']];
                $decremented = DB::table('library.book')
                    ->where('id', $book->id)
                    ->whereNull('deleted_at')
                    ->where('available_copies', '>', 0)
                    ->decrement('available_copies');
                if ($decremented !== 1) {
                    throw new ApiException(
                        'BOOK_NOT_AVAILABLE',
                        "No quedan ejemplares disponibles de «{$book->title}».",
                        409,
                        [['field' => 'items.book_id', 'issue' => 'sin ejemplares']],
                    );
                }

                DB::table('library.loan_item')->insert([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loanId,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category_name,
                    'due_at' => $now->addDays(14),
                    'returned_at' => null,
                ]);
            }

            return $this->findLoan($loanId);
        });

        return response()->json($this->serializeLoan($loan), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:open,overdue,closed'],
            'member_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['size'] ?? 20);
        $now = now('UTC');
        $query = DB::table('library.loan as l');

        if (isset($data['from'])) {
            $query->where('l.loaned_at', '>=', CarbonImmutable::parse($data['from'])->utc()->startOfDay());
        }
        if (isset($data['to'])) {
            $query->where('l.loaned_at', '<', CarbonImmutable::parse($data['to'])->utc()->addDay()->startOfDay());
        }
        if (isset($data['member_id'])) {
            $query->where('l.member_id', $data['member_id']);
        }
        if (($data['status'] ?? null) === 'closed') {
            $query->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('library.loan_item as li')
                ->whereColumn('li.loan_id', 'l.id')
                ->whereNull('li.returned_at'));
        } elseif (($data['status'] ?? null) === 'overdue') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('library.loan_item as li')
                ->whereColumn('li.loan_id', 'l.id')
                ->whereNull('li.returned_at')
                ->where('li.due_at', '<', $now));
        } elseif (($data['status'] ?? null) === 'open') {
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('library.loan_item as li')
                ->whereColumn('li.loan_id', 'l.id')
                ->whereNull('li.returned_at'))
                ->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('library.loan_item as li')
                    ->whereColumn('li.loan_id', 'l.id')
                    ->whereNull('li.returned_at')
                    ->where('li.due_at', '<', $now));
        }

        $total = (clone $query)->count('l.id');
        $loans = $query->orderByDesc('l.loaned_at')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();
        $items = $loans->map(fn (object $loan): array => $this->serializeLoan($this->findLoan($loan->id)));

        return response()->json(compact('items', 'page', 'size', 'total'));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->serializeLoan($this->findLoan($id)));
    }

    public function returnItem(string $loan_id, string $item_id): JsonResponse
    {
        $item = DB::transaction(function () use ($loan_id, $item_id): object {
            $item = DB::table('library.loan_item')
                ->where('loan_id', $loan_id)
                ->where('id', $item_id)
                ->lockForUpdate()
                ->first();
            if ($item === null) {
                $this->notFound();
            }
            if ($item->returned_at !== null) {
                throw new ApiException(
                    'ITEM_ALREADY_RETURNED',
                    'Este ejemplar ya fue devuelto.',
                    409,
                );
            }

            DB::table('library.loan_item')
                ->where('id', $item_id)
                ->whereNull('returned_at')
                ->update(['returned_at' => now('UTC')]);
            $restocked = DB::table('library.book')
                ->where('id', $item->book_id)
                ->whereColumn('available_copies', '<', 'total_copies')
                ->increment('available_copies');
            if ($restocked !== 1) {
                throw new \LogicException('El inventario no permite devolver este ejemplar.');
            }

            return DB::table('library.loan_item')->where('id', $item_id)->first();
        });

        return response()->json($this->serializeItem($item));
    }

    public function overdue(): JsonResponse
    {
        $items = DB::table('library.loan_item as li')
            ->join('library.loan as l', 'l.id', '=', 'li.loan_id')
            ->join('library.member as m', 'm.id', '=', 'l.member_id')
            ->whereNull('li.returned_at')
            ->where('li.due_at', '<', now('UTC'))
            ->orderBy('li.due_at')
            ->get(['l.id as loan_id', 'li.id as item_id', 'li.book_title', 'm.full_name as member_name', 'li.due_at'])
            ->map(function (object $item): array {
                $due = CarbonImmutable::parse($item->due_at)->utc();

                return [
                    'loan_id' => $item->loan_id,
                    'item_id' => $item->item_id,
                    'book_title' => $item->book_title,
                    'member_name' => $item->member_name,
                    'due_at' => $due->toISOString(),
                    'days_late' => (int) $due->startOfDay()->diffInDays(now('UTC')->startOfDay()),
                ];
            })
            ->sortByDesc('days_late')
            ->values();

        return response()->json(['items' => $items]);
    }

    private function findLoan(string $id): object
    {
        $loan = DB::table('library.loan')->where('id', $id)->first();
        if ($loan === null) {
            $this->notFound();
        }

        $loan->items = DB::table('library.loan_item')
            ->where('loan_id', $id)
            ->orderBy('id')
            ->get();

        return $loan;
    }

    private function serializeLoan(object $loan): array
    {
        $items = $loan->items->map(fn (object $item): array => $this->serializeItem($item));
        $isOpen = $items->contains(fn (array $item): bool => $item['returned_at'] === null);
        $isOverdue = $items->contains(
            fn (array $item): bool => $item['returned_at'] === null && CarbonImmutable::parse($item['due_at'])->isPast(),
        );

        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => CarbonImmutable::parse($loan->loaned_at)->utc()->toISOString(),
            'status' => ! $isOpen ? 'closed' : ($isOverdue ? 'overdue' : 'open'),
            'items' => $items,
        ];
    }

    private function serializeItem(object $item): array
    {
        return [
            'id' => $item->id,
            'book_id' => $item->book_id,
            'book_title' => $item->book_title,
            'isbn' => trim($item->isbn),
            'category_name' => $item->category_name,
            'due_at' => CarbonImmutable::parse($item->due_at)->utc()->toISOString(),
            'returned_at' => $item->returned_at === null
                ? null
                : CarbonImmutable::parse($item->returned_at)->utc()->toISOString(),
        ];
    }

    private function notFound(): never
    {
        throw new ApiException('NOT_FOUND', 'El recurso solicitado no existe.', 404);
    }
}
