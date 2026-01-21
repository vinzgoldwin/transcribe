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
