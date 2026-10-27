<?php

use App\Models\Channel;
use App\Models\Complex;

it('shows the demo developer\'s site with the chat widget embedded', function () {
    $channel = Channel::factory()->widget()->create();
    Complex::factory()->for($channel->tenant)->create(['name' => 'Тёплые холмы']);
    Complex::factory()->create(['name' => 'Чужой квартал']);

    $response = $this->get('/demo/site');

    $response->assertOk()
        ->assertSee('ЖК «Тёплые холмы»')
        ->assertDontSee('Чужой квартал')
        ->assertSee('<script src="'.asset('widget/widget.js').'" data-key="'.$channel->external_id.'" async></script>', false);
});

it('tells how to connect the widget while there is none', function () {
    Channel::factory()->widget()->inactive()->create();

    $this->get('/demo/site')
        ->assertOk()
        ->assertSee('php artisan widget:connect --tenant=1')
        ->assertDontSee('widget/widget.js');
});
