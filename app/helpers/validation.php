<?php

declare(strict_types=1);

use App\Core\Validator;

/**
 * Validation helper.
 *
 * Usage:
 *   $validator = validator($request->all(), [
 *       'email'    => 'required|email',
 *       'password' => 'required|string|min:8',
 *   ]);
 *
 *   if ($validator->fails()) { ... }
 *   $clean = $validator->validated();
 */

if (!function_exists('validator')) {
    function validator(array $data, array $rules, array $labels = []): Validator
    {
        return Validator::make($data, $rules, $labels);
    }
}
