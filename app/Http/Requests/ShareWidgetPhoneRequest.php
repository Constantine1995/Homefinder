<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The phone a visitor of the site widget leaves for the manager, written as people write phones.
 */
class ShareWidgetPhoneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+?[\d\s\-()]{10,20}$/', function (string $attribute, mixed $value, Closure $fail): void {
                $digits = strlen(preg_replace('/\D/', '', (string) $value));

                if ($digits < 10 || $digits > 15) {
                    $fail($this->messages()['phone.regex']);
                }
            }],
        ];
    }

    /**
     * Get the messages the visitor reads under the field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'Напишите телефон, по которому менеджер сможет позвонить.',
            'phone.regex' => 'Похоже, в номере ошибка — напишите его, например, как +7 916 123-45-67.',
        ];
    }

    /**
     * Get the phone as Telegram gives it: «+» and the digits; a Russian number written with 8 or without the country
     * code gets +7.
     */
    public function phone(): string
    {
        $digits = preg_replace('/\D/', '', $this->string('phone')->value());

        $digits = match (true) {
            strlen($digits) === 11 && $digits[0] === '8' => '7'.substr($digits, 1),
            strlen($digits) === 10 => '7'.$digits,
            default => $digits,
        };

        return "+{$digits}";
    }
}
