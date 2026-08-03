<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Preferences\FollowMerchantAction;
use App\Actions\Preferences\UnfollowMerchantAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PreferenceResource;
use App\Models\Merchant;
use App\Models\Preference;
use Throwable;

class PreferenceMerchantController extends Controller
{
    public function store(Preference $preference, Merchant $merchant, FollowMerchantAction $action)
    {
        try {
            return $this->response(new PreferenceResource($action->handle($preference, $merchant)), 201, 'Comercio agregado a tus preferencias');
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al guardar el comercio');
        }
    }

    public function destroy(Preference $preference, Merchant $merchant, UnfollowMerchantAction $action)
    {
        try {
            return $this->response(new PreferenceResource($action->handle($preference, $merchant)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al quitar el comercio');
        }
    }
}
