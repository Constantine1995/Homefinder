<?php

use App\Models\MortgageProgram;
use App\Models\Tenant;
use Database\Seeders\DemoMortgageProgramsSeeder;

it('gives the demo developer its mortgage programs and updates them in place on a second run', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    MortgageProgram::factory()->recycle($otherTenant)->create(['name' => 'Семейная ипотека', 'rate' => 5]);
    $seeder = app(DemoMortgageProgramsSeeder::class);

    $seeder->run($tenant);
    MortgageProgram::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', 'Семейная ипотека')->update(['rate' => 7]);
    $seeder->run($tenant);

    $programs = MortgageProgram::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('id')->get();
    expect($programs->pluck('name')->all())->toBe(['Семейная ипотека', 'IT-ипотека', 'Ипотека с субсидией застройщика', 'Стандартная ипотека']);
    expect($programs->first()->rate)->toBe('6.00');
    expect(MortgageProgram::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->sole()->rate)->toBe('5.00');
});
