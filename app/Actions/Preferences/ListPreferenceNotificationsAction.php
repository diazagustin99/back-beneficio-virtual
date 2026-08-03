<?php

namespace App\Actions\Preferences;

use App\Models\Preference;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPreferenceNotificationsAction
{
    public function handle(Preference $preference, int $perPage = 15): LengthAwarePaginator
    {
        return $preference->notifications()->paginate($perPage);
    }
}
