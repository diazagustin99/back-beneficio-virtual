<?php

namespace App\Services\ApiResponse\Strategies;

use App\Services\ApiResponse\Interfaces\ApiResponseStrategyInterface;
use Illuminate\Http\JsonResponse;

class PaginatorResponse implements ApiResponseStrategyInterface
{
    public function format($data, ?int $statusCode = 200, ?string $message = 'exitoso!'): JsonResponse
    {
        /** @var LengthAwarePaginator $data */
        return response()->json([
            'data' => $data->items(),
            'message' => $message,
            'current_page' => $data->currentPage(),
            'total_pages' => $data->lastPage(),
            'total_registros' => $data->total(),
        ], $statusCode);
    }
}
