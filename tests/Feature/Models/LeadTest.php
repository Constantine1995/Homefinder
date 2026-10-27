<?php

use App\Enums\LeadPayment;
use App\Enums\LeadScore;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;

it('scores the lead by what the buyer told and whether they can be reached', function (array $fields, bool $phone, LeadScore $score) {
    $contact = $phone ? Contact::factory()->withPhone()->create() : Contact::factory()->create();
    $conversation = Conversation::factory()->withContact($contact)->create();

    $lead = Lead::factory()->for($conversation)->create([
        'contact_id' => $contact->id, 'budget' => null, 'rooms' => [], ...$fields,
    ]);

    expect($lead->refresh()->score)->toBe($score);
})->with([
    'nothing told' => [['family' => 'двое детей'], false, LeadScore::Cold],
    'one parameter' => [['budget' => 9_000_000], false, LeadScore::Cold],
    'two parameters' => [['budget' => 9_000_000, 'rooms' => [2]], false, LeadScore::Warm],
    'a viewing alone' => [['wants_viewing' => true], false, LeadScore::Warm],
    'a phone alone' => [[], true, LeadScore::Warm],
    'three parameters, not reachable' => [['budget' => 9_000_000, 'rooms' => [2], 'payment' => LeadPayment::Mortgage], false, LeadScore::Warm],
    'three parameters and a viewing' => [['budget' => 9_000_000, 'rooms' => [2], 'deadline_year' => 2027, 'wants_viewing' => true], false, LeadScore::Hot],
    'three parameters and a phone' => [['payment' => LeadPayment::Cash, 'rooms' => [0], 'deadline_year' => 2027], true, LeadScore::Hot],
]);
