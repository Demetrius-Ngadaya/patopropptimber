<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A comma / space separated list of email addresses. */
class EmailList implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value)) {
            $fail(__('Enter email addresses separated by commas.'));

            return;
        }
        foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $fail(__(':email is not a valid email address.', ['email' => $email]));

                return;
            }
        }
    }
}
