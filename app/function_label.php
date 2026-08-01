<?php

declare(strict_types=1);

/**
 * Return the user-facing name of a persisted application function.
 *
 * Function keys are part of authorization, database and audit contracts. The
 * presentation layer therefore translates them without changing the value
 * submitted by forms or stored in operational evidence.
 */
function estab_function_display_name(string $function): string
{
    return $function === 'A/W' ? 'Fernmelder' : $function;
}

/** Return a concise user-facing function/role description. */
function estab_function_identity_display_name(
    string $function,
    string $role,
    string $separator = ' · '
): string {
    $functionLabel = estab_function_display_name($function);
    if ($functionLabel === '') {
        return $role;
    }
    if ($role === '' || ($function === 'A/W' && $role === 'Fernmelder')) {
        return $functionLabel;
    }
    return $functionLabel . $separator . $role;
}

/**
 * Translate canonical function/role fragments inside generated evidence text.
 *
 * The stored text remains byte-for-byte unchanged. This helper is only for
 * rendering known application-generated identity fragments.
 */
function estab_function_display_text(string $text): string
{
    return str_replace(
        ['A/W (Fernmelder)', 'A/W · Fernmelder', 'A/W / Fernmelder'],
        'Fernmelder',
        $text
    );
}

/**
 * Render function-valued properties in canonical JSON with display names.
 *
 * Hashes, exports and stored JSON continue to use the original bytes. Free
 * text is deliberately not rewritten; only properties whose key ends in
 * "function" or "funktion" are presentation-normalized.
 */
function estab_function_display_json(string $json): string
{
    if ($json === '') {
        return '';
    }
    try {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $changed = false;
        $translate = static function (
            mixed $item,
            bool $functionValue = false
        ) use (&$translate, &$changed): mixed {
            if (is_array($item)) {
                $translated = [];
                foreach ($item as $key => $child) {
                    $childIsFunction = $functionValue || (
                        is_string($key)
                        && preg_match(
                            '/(?:^|_)(?:function|funktion)\z/D',
                            $key
                        ) === 1
                    );
                    $translated[$key] = $translate($child, $childIsFunction);
                }
                return $translated;
            }
            if ($functionValue && is_string($item)) {
                $display = estab_function_display_name($item);
                if ($display !== $item) {
                    $changed = true;
                }
                return $display;
            }
            return $item;
        };
        $displayValue = $translate($value);
        if (!$changed) {
            return $json;
        }
        return json_encode(
            $displayValue,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    } catch (JsonException) {
        return $json;
    }
}
