<?php

it('draws the floor plan of a layout as svg', function () {
    $response = $this->get(route('demo.plans.show', ['layout' => '2E-42']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSeeInOrder(['Кухня-гостиная', '17,1 м²', 'Спальня', '12,6 м²']);
    expect(simplexml_load_string($response->getContent()))->not->toBeFalse();
});

it('returns 404 for an unknown layout', function () {
    $response = $this->get('/demo/plans/9X-99.svg');

    $response->assertNotFound();
});
