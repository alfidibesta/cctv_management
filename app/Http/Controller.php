<?php

declare(strict_types=1);

namespace Argus\Http;

/**
 * Shared controller helpers for input handling and responses.
 */
abstract class Controller
{
    protected function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    protected function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function bool(string $key): bool
    {
        return in_array((string) $this->input($key, ''), ['1', 'on', 'true', 'yes'], true);
    }

    protected function only(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $source[$key] ?? null;
        }

        return $result;
    }

    protected function json(array $payload, int $status = 200): never
    {
        json_response($payload, $status);
    }
}
