<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTranscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'in:video/mp4'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'stop_after' => ['nullable', 'string', 'in:whisper,azure,deepl'],
            'source_language' => [
                'nullable',
                'string',
                Rule::in(array_keys((array) config('transcribe.language.supported', []))),
            ],
            'prefer_subtitles' => ['nullable', 'boolean'],
            'subtitle_source' => ['nullable', 'string', 'in:auto,embedded,ocr,audio'],
        ];
    }

    public function messages(): array
    {
        return [
            'filename.required' => 'Please provide the original file name.',
            'content_type.in' => 'Only MP4 uploads are supported.',
            'size_bytes.min' => 'The file size must be greater than zero.',
            'stop_after.in' => 'Stop after must be whisper or translate.',
            'source_language.in' => 'Source language must be Japanese or Chinese.',
            'subtitle_source.in' => 'Subtitle source must be auto, embedded, OCR, or audio.',
        ];
    }
}
