<?php

use App\Jobs\EmbedKnowledge;
use App\Models\Complex;
use App\Models\KbDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

it('imports the demo knowledge base for a developer and queues computing its vectors', function () {
    Queue::fake([EmbedKnowledge::class]);
    $tenant = Tenant::factory()->create();
    foreach (['Отрадный квартал', 'Речная терраса', 'Тёплые холмы'] as $name) {
        Complex::factory()->for($tenant)->create(['name' => $name]);
    }

    $this->artisan('kb:import', ['path' => base_path('resources/demo/knowledge'), '--tenant' => $tenant->id])
        ->expectsTable(['Documents', 'Count'], [['Added', 7], ['Updated', 0], ['Unchanged', 0]])
        ->assertSuccessful();

    expect(KbDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('complex_id')->count())->toBe(3);
    Queue::assertPushed(EmbedKnowledge::class, 1);
});

it('needs an existing developer', function () {
    $this->artisan('kb:import', ['path' => base_path('resources/demo/knowledge'), '--tenant' => 999])
        ->expectsOutputToContain('Give the ID of an existing developer with --tenant.')
        ->assertFailed();
});

it('fails on a document about a complex the developer does not have', function () {
    $tenant = Tenant::factory()->create();

    $this->artisan('kb:import', ['path' => base_path('resources/demo/knowledge/otradny.md'), '--tenant' => $tenant->id])
        ->expectsOutputToContain('The developer has no complex named «Отрадный квартал».')
        ->assertFailed();
});
