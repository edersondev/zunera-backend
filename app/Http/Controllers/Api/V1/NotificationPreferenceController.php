<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferenceRequest;
use App\Http\Resources\Notifications\NotificationPreferenceResource;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\Request;

final class NotificationPreferenceController extends Controller
{
    public function index(Request $request, NotificationPreferenceService $preferences): NotificationPreferenceResource
    {
        return new NotificationPreferenceResource($preferences->all((int) $request->user()->id));
    }

    public function update(UpdateNotificationPreferenceRequest $request, string $category, NotificationPreferenceService $preferences): NotificationPreferenceResource
    {
        if (! in_array($category, NotificationPreferenceService::CATEGORIES, true)) {
            abort(404);
        }

        $preferences->set((int) $request->user()->id, $category, $request->enabled());

        return new NotificationPreferenceResource($preferences->all((int) $request->user()->id));
    }
}
