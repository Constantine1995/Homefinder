<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request; the agent service takes messages up to 4000 characters.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * Get the message as the buyer wrote it, without the surrounding spaces.
     */
    public function text(): string
    {
        return $this->string('text')->trim()->value();
    }
}
