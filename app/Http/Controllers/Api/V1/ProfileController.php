<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\Auth\UserResource;
use App\Services\Authentication\ProfileService;
use Illuminate\Http\Response;

final class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request, ProfileService $service): UserResource
    {
        return new UserResource($service->updateName($request->user(), (string) $request->validated('name')));
    }

    public function changePassword(ChangePasswordRequest $request, ProfileService $service): Response
    {
        $service->changePassword(
            $request->user(),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
            $request->session()->getId(),
        );

        return response()->noContent();
    }
}
