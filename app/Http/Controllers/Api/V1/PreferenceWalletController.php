<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Preferences\FollowWalletAction;
use App\Actions\Preferences\UnfollowWalletAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PreferenceResource;
use App\Models\Preference;
use App\Models\Wallet;
use Throwable;

class PreferenceWalletController extends Controller
{
    public function store(Preference $preference, Wallet $wallet, FollowWalletAction $action)
    {
        try {
            return $this->response(new PreferenceResource($action->handle($preference, $wallet)), 201, 'Billetera agregada a tus preferencias');
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al guardar la billetera');
        }
    }

    public function destroy(Preference $preference, Wallet $wallet, UnfollowWalletAction $action)
    {
        try {
            return $this->response(new PreferenceResource($action->handle($preference, $wallet)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al quitar la billetera');
        }
    }
}
