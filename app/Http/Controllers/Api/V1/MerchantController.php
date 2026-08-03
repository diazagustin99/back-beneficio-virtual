<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Merchants\ListMerchantsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListMerchantsRequest;
use App\Http\Resources\Api\V1\MerchantResource;
use App\Models\Merchant;
use Throwable;

class MerchantController extends Controller
{
    public function index(ListMerchantsRequest $request, ListMerchantsAction $action)
    {
        try {
            $filters = $request->validated();

            if (array_key_exists('with_discounts', $filters)) {
                $filters['with_discounts'] = $request->boolean('with_discounts');
            }

            if (array_key_exists('with_logo_first', $filters)) {
                $filters['with_logo_first'] = $request->boolean('with_logo_first');
            }

            $merchants = $action->handle($filters);

            return $this->response($merchants->through(fn (Merchant $merchant) => new MerchantResource($merchant)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar comercios');
        }
    }

    public function show(Merchant $merchant)
    {
        try {
            return $this->response(new MerchantResource($merchant));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener el comercio');
        }
    }
}
