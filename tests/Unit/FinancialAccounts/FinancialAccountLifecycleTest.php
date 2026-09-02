<?php

declare(strict_types=1);

namespace Tests\Unit\FinancialAccounts;

use App\Exceptions\FinancialAccounts\FinancialAccountStateException;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FinancialAccounts\FinancialAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinancialAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function archive_changes_active_account_to_archived_and_restore_returns_it(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $service = app(FinancialAccountService::class);

        $archived = $service->archive($user, $account);

        $this->assertTrue($archived->status->isArchived());
        $this->assertNotNull($archived->archived_at);

        $restored = $service->restore($user, $archived);

        $this->assertTrue($restored->status->isActive());
        $this->assertNull($restored->archived_at);
    }

    #[Test]
    public function repeated_lifecycle_actions_throw_state_conflicts(): void
    {
        $user = User::factory()->create();
        $account = FinancialAccount::factory()->create(['user_id' => $user->id]);
        $service = app(FinancialAccountService::class);

        $service->archive($user, $account);

        try {
            $service->archive($user, $account);
            $this->fail('Expected archive conflict.');
        } catch (FinancialAccountStateException $exception) {
            $this->assertSame('account_already_archived', $exception->errorCode());
        }

        $service->restore($user, $account);

        $this->expectException(FinancialAccountStateException::class);
        $service->restore($user, $account);
    }
}
