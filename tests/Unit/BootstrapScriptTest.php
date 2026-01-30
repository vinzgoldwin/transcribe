<?php

test('bootstrap script exists and has a bash shebang', function () {
    $path = dirname(__DIR__, 2).'/bootstrap.sh';

    expect(file_exists($path))->toBeTrue();

    $contents = file_get_contents($path);

    expect(is_string($contents))->toBeTrue()
        ->and(str_starts_with($contents, '#!/usr/bin/env bash'))->toBeTrue()
        ->and(str_contains($contents, '--install-ffmpeg'))->toBeTrue();
});
