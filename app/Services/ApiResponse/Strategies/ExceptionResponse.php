<?php

namespace App\Services\ApiResponse\Strategies;

use App\Services\ApiResponse\Interfaces\ApiResponseStrategyInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ExceptionResponse implements ApiResponseStrategyInterface
{
    public function format($data, ?int $statusCode = 400, ?string $message = 'Error inesperado'): JsonResponse
    {
        $errors = [
            'error' => $message.': '.$data->getMessage(),
        ];

        // Si estamos en modo debug, agregamos información detallada
        if (config('app.debug')) {
            $details = [
                'file' => $data->getFile(),
                'line' => $data->getLine(),
                'trace' => collect($data->getTrace())
                    ->take(5) // Mostramos solo las primeras 5 líneas para no saturar
                    ->map(fn ($t) => [
                        'file' => $t['file'] ?? null,
                        'line' => $t['line'] ?? null,
                        'function' => $t['function'] ?? null,
                        'class' => $t['class'] ?? null,
                    ])
                    ->filter()
                    ->values(),
            ];
        }

        Log::error('ExceptionResponse: '.$data->getMessage(), [
            'exception' => $data,
        ]);

        /** @var Throwable $data */
        return response()->json([
            'errors' => $errors,
            'details' => $details ?? [],
        ], $statusCode);
    }
}
