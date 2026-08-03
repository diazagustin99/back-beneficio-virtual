<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Promotions\ListWelcomeCarouselAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionListResource;
use App\Models\Promotion;
use Throwable;

class WelcomeCarouselController extends Controller
{
    public function index(ListWelcomeCarouselAction $action)
    {
        try {
            $promotions = $action->handle();

            return $this->response($promotions->map(fn (Promotion $promotion) => new PromotionListResource($promotion)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener el carrusel de bienvenida');
        }
    }
}
