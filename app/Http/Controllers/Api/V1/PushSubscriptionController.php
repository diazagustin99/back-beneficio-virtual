<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Preferences\StorePushSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePushSubscriptionRequest;
use App\Models\Preference;
use Throwable;

class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request, Preference $preference, StorePushSubscriptionAction $action)
    {
        try {
            $action->handle($preference, $request->validated());

            return $this->response(null, 201, 'Suscripción guardada');
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al guardar la suscripción push');
        }
    }
}
