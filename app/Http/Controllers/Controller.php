<?php

namespace App\Http\Controllers;

use App\Services\ApiResponse\ApiResponseFormatter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;

    protected function response(
        mixed $data,
        int $statusCode = 200,
        string $message = '¡Exitoso!'
    ) {
        return ApiResponseFormatter::format($data, $statusCode, $message);
    }
}
