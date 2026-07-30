<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Wallets\ListWalletsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListWalletsRequest;
use App\Http\Resources\Api\V1\WalletResource;
use App\Models\Wallet;
use Throwable;

class WalletController extends Controller
{
    public function index(ListWalletsRequest $request, ListWalletsAction $action)
    {
        try {
            $filters = $request->validated();

            if (array_key_exists('is_active', $filters)) {
                $filters['is_active'] = $request->boolean('is_active');
            }

            $wallets = $action->handle($filters);

            return $this->response($wallets->map(fn (Wallet $wallet) => new WalletResource($wallet)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar billeteras');
        }
    }

    public function show(Wallet $wallet)
    {
        try {
            return $this->response(new WalletResource($wallet));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener la billetera');
        }
    }
}
