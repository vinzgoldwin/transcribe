<?php

use App\Services\Transcription\Stt\WhisperCppSttProvider;

it('parses srt output into segments', function () {
    $srt = <<<'SRT'
1
00:00:00,000 --> 00:00:02,500
こんにちは

2
00:00:02,500 --> 00:00:04,000
世界
みなさん

SRT;

    $segments = WhisperCppSttProvider::parseSrt($srt);

    expect($segments)->toEqual([
        [
            'start' => 0.0,
            'end' => 2.5,
            'text' => 'こんにちは',
        ],
        [
            'start' => 2.5,
            'end' => 4.0,
            'text' => '世界 みなさん',
        ],
    ]);
});

it('drops invalid utf8 from srt output', function () {
    $invalid = "1\n00:00:00,000 --> 00:00:01,000\nhello ".chr(0xC3).chr(0x28)."\n";

    $segments = WhisperCppSttProvider::parseSrt($invalid);

    expect($segments)->toBeArray()
        ->and($segments[0]['text'] ?? '')->toBeString()
        ->and(preg_match('//u', (string) ($segments[0]['text'] ?? '')))->toBe(1);
});
