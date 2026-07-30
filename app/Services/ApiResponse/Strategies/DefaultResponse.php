<?php

namespace App\Services\ApiResponse\Strategies;

use App\Services\ApiResponse\Interfaces\ApiResponseStrategyInterface;
use Illuminate\Http\JsonResponse;

class DefaultResponse implements ApiResponseStrategyInterface
{
    public function format($data, ?int $statusCode = 200, ?string $message = 'exitoso!'): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
        ], $statusCode);
    }
}
