<?php

namespace App\Services\ApiResponse\Strategies;

use App\Services\ApiResponse\Interfaces\ApiResponseStrategyInterface;
use Illuminate\Http\JsonResponse;

class CollectionResponse implements ApiResponseStrategyInterface
{
    public function format($data, ?int $statusCode = 200, ?string $message = 'exitoso!'): JsonResponse
    {
        /** @var Collection $data */
        return response()->json([
            'data' => $data,
            'message' => $message,
            'current_page' => 1,
            'total_pages' => 1,
            'total_registros' => $data->count(),
        ], $statusCode);
    }
}
