<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Preferences\CompleteOnboardingAction;
use App\Actions\Preferences\ShowPreferenceAction;
use App\Actions\Preferences\UpdateNotificationPreferenceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompleteOnboardingRequest;
use App\Http\Requests\Api\V1\UpdateNotificationPreferenceRequest;
use App\Http\Resources\Api\V1\PreferenceResource;
use App\Models\Preference;
use Throwable;

class PreferenceController extends Controller
{
    public function store(CompleteOnboardingRequest $request, CompleteOnboardingAction $action)
    {
        try {
            ['preference' => $preference, 'email_taken' => $emailTaken] = $action->handle($request->validated());

            $payload = array_merge(
                (new PreferenceResource($preference))->toArray($request),
                ['email_taken' => $emailTaken],
            );

            return $this->response($payload, 201, 'Onboarding completado');
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al completar el onboarding');
        }
    }

    public function show(Preference $preference, ShowPreferenceAction $action)
    {
        try {
            return $this->response(new PreferenceResource($action->handle($preference)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al obtener las preferencias');
        }
    }

    public function update(UpdateNotificationPreferenceRequest $request, Preference $preference, UpdateNotificationPreferenceAction $action)
    {
        try {
            $updated = $action->handle($preference, $request->boolean('wants_notifications'));

            return $this->response(new PreferenceResource($updated));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al actualizar las preferencias');
        }
    }
}
