<?php

namespace App\Services\ApiResponse;

use App\Services\ApiResponse\Strategies\CollectionResponse;
use App\Services\ApiResponse\Strategies\DefaultResponse;
use App\Services\ApiResponse\Strategies\ExceptionResponse;
use App\Services\ApiResponse\Strategies\PaginatorResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Throwable;

class ApiResponseFormatter
{
    public static function format($data, ?int $statusCode = 200, ?string $message = 'exitoso!')
    {
        $strategy = match (true) {
            $data instanceof LengthAwarePaginator => new PaginatorResponse,
            $data instanceof Collection => new CollectionResponse,
            $data instanceof Throwable => new ExceptionResponse,
            default => new DefaultResponse,
        };

        return $strategy->format($data, $statusCode, $message);
    }
}
