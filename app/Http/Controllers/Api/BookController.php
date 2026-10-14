<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Domain\Book\Isbn;
use App\Domain\Book\Inventory;
use App\Domain\Book\Quantity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:250'],
            'category_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['size'] ?? 20);
        $query = DB::table('library.book as b')
            ->join('library.category as c', 'c.id', '=', 'b.category_id')
            ->whereNull('b.deleted_at')
            ->select('b.*', 'c.id as category_id', 'c.name as category_name');

        if (isset($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('b.title ILIKE ?', [$term])
                    ->orWhereRaw('b.author ILIKE ?', [$term]);
            });
        }
        if (isset($data['category_id'])) {
            $query->where('b.category_id', $data['category_id']);
        }

        $total = (clone $query)->count('b.id');
        $items = $query->orderBy('b.title')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get()
            ->map(fn (object $book): array => $this->serializeBook($book));

        return response()->json(compact('items', 'page', 'size', 'total'));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->serializeBook($this->findBook($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'isbn' => ['required', 'regex:/^[0-9]{13}$/'],
            'category_id' => ['required', 'uuid', Rule::exists('library.category', 'id')],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);
        $isbn = new Isbn($data['isbn']);
        $total = new Quantity($data['total_copies']);
        $id = (string) Str::uuid();
        DB::table('library.book')->insert([
            'id' => $id,
            'title' => trim($data['title']),
            'author' => trim($data['author']),
            'isbn' => $isbn->value,
            'category_id' => $data['category_id'],
            'total_copies' => $total->value,
            'available_copies' => $total->value,
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        return response()->json($this->serializeBook($this->findBook($id)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'uuid', Rule::exists('library.category', 'id')],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);

        DB::transaction(function () use ($id, $data): void {
            $book = DB::table('library.book')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($book === null) {
                $this->notFound();
            }

            $inventory = (new Inventory(
                (int) $book->total_copies,
                (int) $book->available_copies,
            ))->changeTotal(new Quantity($data['total_copies']));
            DB::table('library.book')->where('id', $id)->update([
                'title' => trim($data['title']),
                'author' => trim($data['author']),
                'category_id' => $data['category_id'],
                'total_copies' => $data['total_copies'],
                'available_copies' => $inventory->available,
            ]);
        });

        return response()->json($this->serializeBook($this->findBook($id)));
    }

    public function destroy(string $id): \Illuminate\Http\Response
    {
        DB::transaction(function () use ($id): void {
            $book = DB::table('library.book')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($book === null) {
                $this->notFound();
            }

            $hasOpenLoans = DB::table('library.loan_item')
                ->where('book_id', $id)
                ->whereNull('returned_at')
                ->exists();
            if ($hasOpenLoans) {
                throw new ApiException(
                    'BOOK_HAS_OPEN_LOANS',
                    'No se puede dar de baja un libro con préstamos abiertos.',
                    409,
                );
            }

            DB::table('library.book')->where('id', $id)->update(['deleted_at' => now()]);
        });

        return response()->noContent();
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);
        $book = $this->findBook($id);
        $oldKey = $book->cover_key;
        $key = (string) Str::uuid();
        $path = Storage::disk('covers')->putFileAs('', $request->file('file'), $key);

        if ($path === false) {
            throw new \RuntimeException('No fue posible guardar la portada.');
        }

        DB::table('library.book')->where('id', $id)->update(['cover_key' => $key]);
        if ($oldKey !== null) {
            Storage::disk('covers')->delete($oldKey);
        }

        return response()->json($this->serializeBook($this->findBook($id)));
    }

    public function deleteCover(string $id): \Illuminate\Http\Response
    {
        $book = $this->findBook($id);
        $key = $book->cover_key;
        DB::transaction(fn () => DB::table('library.book')->where('id', $id)->update(['cover_key' => null]));
        if ($key !== null && ! Storage::disk('covers')->delete($key)) {
            throw new \RuntimeException('No fue posible eliminar la portada almacenada.');
        }

        return response()->noContent();
    }

    public function cover(string $id): StreamedResponse
    {
        $book = $this->findBook($id);
        if ($book->cover_key === null || ! Storage::disk('covers')->exists($book->cover_key)) {
            throw new ApiException('NOT_FOUND', 'El recurso solicitado no existe.', 404);
        }

        return Storage::disk('covers')->response($book->cover_key);
    }

    private function findBook(string $id): object
    {
        $book = DB::table('library.book as b')
            ->join('library.category as c', 'c.id', '=', 'b.category_id')
            ->where('b.id', $id)
            ->whereNull('b.deleted_at')
            ->select('b.*', 'c.id as category_id', 'c.name as category_name')
            ->first();

        if ($book === null) {
            $this->notFound();
        }

        return $book;
    }

    private function serializeBook(object $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => trim($book->isbn),
            'category' => ['id' => $book->category_id, 'name' => $book->category_name],
            'total_copies' => $book->total_copies,
            'available_copies' => $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }

    private function notFound(): never
    {
        throw new ApiException('NOT_FOUND', 'El recurso solicitado no existe.', 404);
    }
}
