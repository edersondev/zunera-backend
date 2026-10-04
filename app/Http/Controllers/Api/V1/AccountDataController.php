<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountData\ArchiveAccountDataRequest;
use App\Http\Requests\AccountData\DeleteAccountDataRequest;
use App\Http\Requests\AccountData\ListArchiveRecordsRequest;
use App\Services\AccountData\AccountDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountDataController extends Controller
{
    public function archive(ArchiveAccountDataRequest $request, AccountDataService $service): JsonResponse
    {
        return response()->json(['data' => $service->archive($request->user())], 201);
    }

    public function delete(DeleteAccountDataRequest $request, AccountDataService $service): Response
    {
        $service->deleteAll($request->user(), (string) $request->validated('current_password'));

        return response()->noContent();
    }

    public function archives(Request $request, AccountDataService $service): JsonResponse
    {
        return response()->json(['data' => $service->archives($request->user())]);
    }

    public function records(ListArchiveRecordsRequest $request, AccountDataService $service, int $archive_id): JsonResponse
    {
        return response()->json($service->records(
            $request->user(),
            $archive_id,
            (string) $request->validated('type'),
            (int) $request->validated('page', 1),
        ));
    }
}
