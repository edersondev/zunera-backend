<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Resources\Notifications\NotificationResource;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function index(ListNotificationsRequest $request, NotificationCenterService $center): JsonResponse
    {
        $page = $center->listing((int) $request->user()->id, $request->view(), $request->limit(), $request->cursor());
        $items = $page['items']->map(static fn ($event): array => (new NotificationResource($event))->toArray($request))->all();

        return response()->json(['data' => $items, 'next_cursor' => $page['next_cursor']]);
    }

    public function summary(Request $request, NotificationCenterService $center): JsonResponse
    {
        return response()->json(['data' => $center->summary((int) $request->user()->id)]);
    }

    public function read(Request $request, NotificationCenterService $center, int $notification_id): JsonResponse
    {
        $event = $center->read((int) $request->user()->id, $notification_id);

        return response()->json(['data' => (new NotificationResource($event))->toArray($request)]);
    }

    public function open(Request $request, NotificationCenterService $center, int $notification_id): JsonResponse
    {
        return $this->read($request, $center, $notification_id);
    }

    public function readAll(Request $request, NotificationCenterService $center): JsonResponse
    {
        return response()->json(['data' => $center->readAll((int) $request->user()->id)]);
    }
}
