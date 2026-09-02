<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthenticatedSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user' => new UserResource($this->resource['user']),
            'session' => [
                'idle_expires_at' => $this->resource['idle_expires_at']->toIso8601String(),
                'absolute_expires_at' => $this->resource['absolute_expires_at']->toIso8601String(),
            ],
        ];
    }
}
