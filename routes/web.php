<?php

use App\Http\Controllers\TranscriptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::get('/', fn () => Inertia::render('Welcome'));

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::get('dashboard', [TranscriptionController::class, 'index'])->name('dashboard');
    Route::post('transcriptions', [TranscriptionController::class, 'store'])->name('transcriptions.store');
    Route::put('transcriptions/{transcription}/upload', [TranscriptionController::class, 'upload'])
        ->middleware('signed')
        ->name('transcriptions.upload');
    Route::post('transcriptions/{transcription}/complete', [TranscriptionController::class, 'complete'])
        ->name('transcriptions.complete');
    Route::post('transcriptions/{transcription}/translate', [TranscriptionController::class, 'translate'])
        ->name('transcriptions.translate');
    Route::get('transcriptions/{transcription}', [TranscriptionController::class, 'show'])
        ->name('transcriptions.show');
    Route::get('transcriptions/{transcription}/status', [TranscriptionController::class, 'status'])
        ->name('transcriptions.status');
    Route::get('transcriptions/{transcription}/download/{format}', [TranscriptionController::class, 'download'])
        ->whereIn('format', ['srt', 'vtt'])
        ->name('transcriptions.download');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
