<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        if ($data['to'] < $data['from']) {
            throw new ApiException(
                'INVALID_DATE_RANGE',
                'La fecha final debe ser igual o posterior a la inicial.',
                422,
            );
        }

        $from = CarbonImmutable::parse($data['from'], 'UTC')->startOfDay();
        $until = CarbonImmutable::parse($data['to'], 'UTC')->addDay()->startOfDay();
        $rows = DB::table('library.loan as l')
            ->join('library.loan_item as li', 'li.loan_id', '=', 'l.id')
            ->where('l.loaned_at', '>=', $from)
            ->where('l.loaned_at', '<', $until)
            ->groupBy('li.book_id', 'li.book_title', 'li.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('li.book_title')
            ->get([
                'li.book_id',
                'li.book_title',
                'li.category_name',
                DB::raw('COUNT(*) AS times_borrowed'),
            ]);

        return response()->json([
            'from' => $data['from'],
            'to' => $data['to'],
            'rows' => $rows,
        ]);
    }
}
