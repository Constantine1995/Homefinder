<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\SavedSearches\SavedSearchNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Sends the developer's subscribed buyers the news of a feed import.
 */
#[DeleteWhenMissingModels]
class NotifySavedSearches implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Tenant $tenant) {}

    /**
     * One run per developer at a time: two imports in a row must not tell a buyer twice.
     */
    public function uniqueId(): string
    {
        return (string) $this->tenant->id;
    }

    /**
     * Execute the job.
     */
    public function handle(SavedSearchNotifier $notifier): void
    {
        $this->tenant->run(fn (): int => $notifier->notify());
    }
}
