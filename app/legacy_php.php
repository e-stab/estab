<?php

/** Temporary compatibility helpers for APIs removed after PHP 5. */

if (!function_exists('each')) {
    function each(array &$array): array|false
    {
        $key = key($array);
        if ($key === null) {
            return false;
        }
        $value = current($array);
        next($array);
        return [0 => $key, 'key' => $key, 1 => $value, 'value' => $value];
    }
}

if (!function_exists('split')) {
    function split(string $pattern, string $string, int $limit = -1): array|false
    {
        $expression = '~' . str_replace('~', '\\~', $pattern) . '~';
        return preg_split($expression, $string, $limit === 0 ? -1 : $limit);
    }
}

if (!function_exists('ereg')) {
    function ereg(string $pattern, string $string, ?array &$matches = null): int|false
    {
        $result = preg_match('~' . str_replace('~', '\\~', $pattern) . '~', $string, $matches);
        return $result === 1 ? strlen($matches[0]) : $result;
    }
}

if (!function_exists('eregi')) {
    function eregi(string $pattern, string $string, ?array &$matches = null): int|false
    {
        $result = preg_match('~' . str_replace('~', '\\~', $pattern) . '~i', $string, $matches);
        return $result === 1 ? strlen($matches[0]) : $result;
    }
}

if (!function_exists('ereg_replace')) {
    function ereg_replace(string $pattern, string $replacement, string $string): string
    {
        return preg_replace('~' . str_replace('~', '\\~', $pattern) . '~', $replacement, $string) ?? $string;
    }
}

if (!function_exists('eregi_replace')) {
    function eregi_replace(string $pattern, string $replacement, string $string): string
    {
        return preg_replace('~' . str_replace('~', '\\~', $pattern) . '~i', $replacement, $string) ?? $string;
    }
}

if (!function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime(): false
    {
        return false;
    }
}

if (!function_exists('set_magic_quotes_runtime')) {
    function set_magic_quotes_runtime(bool $newSetting): true
    {
        unset($newSetting);
        return true;
    }
}

