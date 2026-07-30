<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Promotions\ListPromotionSnapshotsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PromotionSnapshotResource;
use App\Models\Promotion;
use App\Models\PromotionSnapshot;
use Throwable;

class PromotionSnapshotController extends Controller
{
    public function index(Promotion $promotion, ListPromotionSnapshotsAction $action)
    {
        try {
            $snapshots = $action->handle($promotion);

            return $this->response($snapshots->map(fn (PromotionSnapshot $snapshot) => new PromotionSnapshotResource($snapshot)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar el historial de la promoción');
        }
    }
}
