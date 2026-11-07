<?php

namespace Tests\Conformance;

use Illuminate\Foundation\Http\FormRequest;

class ConformanceForm extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2',
            'email' => 'required|email',
            'topic' => 'required|in:sales,support',
            // Asked only of support requests: a form shows it while topic is support.
            'company' => 'required_if:topic,support|string',
        ];
    }
}
