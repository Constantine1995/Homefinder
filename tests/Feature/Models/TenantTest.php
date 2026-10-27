<?php

use App\Models\Tenant;

it('makes the tenant current only while the callback runs', function () {
    $previousTenant = Tenant::factory()->create()->makeCurrent();
    $tenant = Tenant::factory()->create();

    $currentIdInCallback = $tenant->run(fn (): ?int => Tenant::currentId());

    expect($currentIdInCallback)->toBe($tenant->id)
        ->and(Tenant::currentId())->toBe($previousTenant->id);
});
