<?php

use Awaisjameel\Texto\Support\TwilioContentApi;
use Illuminate\Support\Facades\Http;

it('creates template and reuses on ensure', function () {
    Http::fake([
        'content.twilio.com/v1/Content' => Http::sequence()
            ->push(['contents' => []], 200)
            ->push(['sid' => 'HX123'], 201)
            ->push(['contents' => [['sid' => 'HX123', 'friendly_name' => 'my_template']]], 200),
    ]);

    $api = new TwilioContentApi('ACXXXX', 'token');
    $sid = $api->ensureTemplate('my_template', function () {
        return [
            'friendly_name' => 'my_template',
            'language' => 'en',
            'variables' => [],
            'types' => ['twilio/text' => ['body' => 'Hello {{1}}']],
        ];
    });
    expect($sid)->toBe('HX123');

    $sid2 = $api->ensureTemplate('my_template', fn() => []);
    expect($sid2)->toBe('HX123');
});
