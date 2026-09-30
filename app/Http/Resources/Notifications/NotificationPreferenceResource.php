<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class NotificationPreferenceResource extends JsonResource
{
    /** @return array<string, bool> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
