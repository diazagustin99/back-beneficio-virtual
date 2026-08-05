<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Preferences\CountUnreadPreferenceNotificationsAction;
use App\Actions\Preferences\ListPreferenceNotificationsAction;
use App\Actions\Preferences\MarkNotificationReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPreferenceNotificationsRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Preference;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

class NotificationController extends Controller
{
    public function index(ListPreferenceNotificationsRequest $request, Preference $preference, ListPreferenceNotificationsAction $action)
    {
        try {
            $notifications = $action->handle($preference, $request->validated('per_page') ?? 15);

            return $this->response($notifications->through(fn (DatabaseNotification $notification) => new NotificationResource($notification)));
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al listar las notificaciones');
        }
    }

    // Deliberately separate from `index`: the frontend polls this on an
    // interval for the header badge, and fetching (and JSON-encoding) full
    // notification payloads just to count them every tick would be wasteful.
    public function unreadCount(Preference $preference, CountUnreadPreferenceNotificationsAction $action)
    {
        try {
            return $this->response(['unread_count' => $action->handle($preference)]);
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al contar las notificaciones');
        }
    }

    public function markRead(Preference $preference, string $notification, MarkNotificationReadAction $action)
    {
        try {
            return $this->response(new NotificationResource($action->handle($preference, $notification)));
        } catch (ModelNotFoundException $e) {
            return $this->response($e, 404, 'Notificación no encontrada');
        } catch (Throwable $e) {
            return $this->response($e, 500, 'Error al marcar la notificación como leída');
        }
    }
}
