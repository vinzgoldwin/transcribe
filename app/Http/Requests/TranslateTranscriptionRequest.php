<?php

namespace App\Http\Requests;

use App\Enums\TranscriptionStatus;
use App\Models\Transcription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TranslateTranscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $transcription = $this->route('transcription');

        return $transcription instanceof Transcription
            && $this->user() !== null
            && $transcription->user_id === $this->user()->id;
    }

    protected function prepareForValidation(): void
    {
        $transcription = $this->route('transcription');

        $this->merge([
            'transcription_status' => $transcription instanceof Transcription
                ? $transcription->status?->value
                : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'transcription_status' => [
                'required',
                Rule::in([TranscriptionStatus::AwaitingTranslation->value]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'transcription_status.required' => 'Transcription is not ready for translation.',
            'transcription_status.in' => 'Transcription is not ready for translation.',
        ];
    }
}
