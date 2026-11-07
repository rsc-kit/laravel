<?php

namespace Tests\Conformance;

use Illuminate\Validation\Rule;

/**
 * An admin panel's update: the rules read which record from the route, as
 * Rule::unique('users')->ignore($this->route('record')) would. The address is
 * record 7's, so only record 7 may keep it.
 */
class ConformanceUpdate extends ConformanceForm
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $taken = $this->route('record') === '7' ? [] : ['taken@example.com'];

        return [...parent::rules(), 'email' => ['required', 'email', Rule::notIn($taken)]];
    }
}
