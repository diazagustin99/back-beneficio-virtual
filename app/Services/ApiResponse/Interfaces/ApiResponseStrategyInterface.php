<?php

namespace App\Services\ApiResponse\Interfaces;

use Illuminate\Http\JsonResponse;

interface ApiResponseStrategyInterface
{
    public function format($data, ?int $statusCode = 200, ?string $message = 'exitoso!'): JsonResponse;
}
