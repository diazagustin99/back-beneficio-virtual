<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PromotionCategories\ListPromotionCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionCategoryResource;
use App\Models\PromotionCategory;
use Throwable;

class PromotionCategoryController extends Controller
{
    public function index(ListPromotionCategoriesAction $action)
    {
        try {
            $categories = $action->handle();

            return $this->response($categories->map(fn (PromotionCategory $category) => new PromotionCategoryResource($category)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar categorías');
        }
    }

    public function show(PromotionCategory $promotionCategory)
    {
        try {
            return $this->response(new PromotionCategoryResource($promotionCategory));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener la categoría');
        }
    }
}
