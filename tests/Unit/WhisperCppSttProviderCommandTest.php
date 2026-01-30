<?php

use App\Services\Transcription\Stt\WhisperCppSttProvider;

function makeWhisperCppProvider(bool $noGpu): WhisperCppSttProvider
{
    return new class('whisper-cli', '/tmp/whisper.bin', 10, null, 'srt', null, null, true, $noGpu) extends WhisperCppSttProvider
    {
        /**
         * @return array<int, string>
         */
        public function commandFor(string $audioPath, string $language, string $format): array
        {
            return $this->buildCommand($audioPath, $language, $format);
        }
    };
}

it('adds the no-gpu flag when configured', function () {
    $provider = makeWhisperCppProvider(true);

    $command = $provider->commandFor('/tmp/audio.wav', 'en', 'srt');

    expect($command)->toContain('-ng');
});

it('omits the no-gpu flag when disabled', function () {
    $provider = makeWhisperCppProvider(false);

    $command = $provider->commandFor('/tmp/audio.wav', 'en', 'srt');

    expect($command)->not->toContain('-ng');
});
