<?php

use App\Models\Complex;
use App\Models\Tenant;

it('limits queries to the current tenant', function () {
    $tenant = Tenant::factory()->create();
    $ownComplex = Complex::factory()->for($tenant)->create();
    Complex::factory()->create();

    $tenant->makeCurrent();

    expect(Complex::pluck('id')->all())->toBe([$ownComplex->id]);
});

it('does not limit queries when no tenant is current', function () {
    Complex::factory()->count(2)->create();

    expect(Complex::count())->toBe(2);
});

it('assigns new models to the current tenant', function () {
    $tenant = Tenant::factory()->create()->makeCurrent();

    $complex = Complex::create(['name' => 'ЖК «Северный парк»']);

    expect($complex->tenant_id)->toBe($tenant->id);
});

it('keeps a tenant assigned explicitly', function () {
    Tenant::factory()->create()->makeCurrent();
    $otherTenant = Tenant::factory()->create();

    $complex = Complex::factory()->for($otherTenant)->create();

    expect($complex->tenant_id)->toBe($otherTenant->id);
});
