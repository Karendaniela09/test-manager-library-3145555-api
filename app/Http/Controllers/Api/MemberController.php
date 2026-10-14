<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['size'] ?? 20);
        $query = DB::table('library.member');

        if (isset($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw('full_name ILIKE ?', [$term])
                ->orWhereRaw('document_number ILIKE ?', [$term]));
        }

        $total = (clone $query)->count();
        $items = $query->orderBy('full_name')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get()
            ->map(fn (object $member): array => $this->serializeMember($member));

        return response()->json(compact('items', 'page', 'size', 'total'));
    }

    public function show(string $id): JsonResponse
    {
        $member = DB::table('library.member')->where('id', $id)->first();
        if ($member === null) {
            $this->notFound();
        }

        return response()->json($this->serializeMember($member, true));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'document_number' => ['required', 'string', 'max:30'],
        ]);
        $member = [
            'id' => (string) Str::uuid(),
            'full_name' => trim($data['full_name']),
            'document_number' => trim($data['document_number']),
        ];
        DB::table('library.member')->insert($member);

        return response()->json($this->serializeMember((object) $member), 201);
    }

    private function serializeMember(object $member, bool $withLoans = false): array
    {
        $result = [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
        ];
        if ($withLoans) {
            $result['active_loans'] = DB::table('library.loan_item as li')
                ->join('library.loan as l', 'l.id', '=', 'li.loan_id')
                ->where('l.member_id', $member->id)
                ->whereNull('li.returned_at')
                ->count();
        }

        return $result;
    }

    private function notFound(): never
    {
        throw new ApiException('NOT_FOUND', 'El recurso solicitado no existe.', 404);
    }
}
