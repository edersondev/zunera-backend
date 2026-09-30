<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationProjectionData;
use App\Models\NotificationProjectionFact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class NotificationProjectionFactService
{
    public function capture(NotificationProjectionData $data): NotificationProjectionFact
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('A notification projection fact must share the accepted source transaction.');
        }

        User::query()->whereKey($data->userId)->lockForUpdate()->firstOrFail();
        $qualifiedAt = $data->qualifiedAt === null ? null : CarbonImmutable::now();

        $now = now();
        $id = DB::table('notification_projection_facts')->insertGetId([
            'user_id' => $data->userId,
            'source_kind' => $data->sourceKind,
            'source_id' => $data->sourceId,
            'affected_year' => $data->affectedYear,
            'affected_month' => $data->affectedMonth,
            'qualified_type' => $data->qualifiedType,
            'qualified_at' => $qualifiedAt?->format('Y-m-d H:i:s.u'),
            'context' => json_encode($data->context, JSON_THROW_ON_ERROR),
            'available_at' => $now->toDateTimeString(),
            'processed_at' => null,
            'attempts' => 0,
            'last_error_code' => null,
            'created_at' => $now->toDateTimeString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

        return NotificationProjectionFact::query()->findOrFail($id);
    }
}
