<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Promotions\ListPromotionsAction;
use App\Actions\Promotions\ShowPromotionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPromotionsRequest;
use App\Http\Resources\Api\V1\PromotionListResource;
use App\Http\Resources\Api\V1\PromotionResource;
use App\Models\Promotion;
use Throwable;

class PromotionController extends Controller
{
    public function index(ListPromotionsRequest $request, ListPromotionsAction $action)
    {
        try {
            $filters = $request->validated();

            if (array_key_exists('is_active', $filters)) {
                $filters['is_active'] = $request->boolean('is_active');
            }

            $promotions = $action->handle($filters);

            return $this->response($promotions->through(fn (Promotion $promotion) => new PromotionListResource($promotion)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar promociones');
        }
    }

    public function show(Promotion $promotion, ShowPromotionAction $action)
    {
        try {
            return $this->response(new PromotionResource($action->handle($promotion)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener la promoción');
        }
    }
}
