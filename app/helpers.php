<?php
/**
 * Small procedural helpers shared by views and controllers.
 */

declare(strict_types=1);

use Argus\Support\Auth;
use Argus\Support\Csrf;
use Argus\Support\View;

if (!function_exists('e')) {
    /** Escape a value for HTML output. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_path')) {
    function base_path(): string
    {
        return rtrim((string) \Argus\Support\Config::get('app.base_path', ''), '/');
    }
}

if (!function_exists('url')) {
    /**
     * Build an application URL honouring an optional sub-directory install.
     */
    function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        $base = base_path();

        // Auto-detect sub-directory when running behind Apache/nginx.
        if ($base === '') {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            if ($dir !== '' && $dir !== '.' && $dir !== '/' && !str_contains($dir, 'index.php')) {
                $base = $dir;
            }
        }

        return $base . ($path === '/' ? '/' : $path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $file = BASE_PATH . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : ARGUS_VERSION;

        return url('/assets/' . ltrim($path, '/')) . '?v=' . $version;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): never
    {
        header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, 302);
        exit;
    }
}

if (!function_exists('view')) {
    /** Render a view inside the given layout and print it. */
    function view(string $template, array $data = [], ?string $layout = 'layouts/app'): void
    {
        echo View::render($template, $data, $layout);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): string
    {
        return e($_SESSION['_old'][$key] ?? $default);
    }
}

if (!function_exists('flash')) {
    /** Queue a flash message: flash('Camera saved', 'success') */
    function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['_flash'][] = ['message' => $message, 'type' => $type];
    }
}

if (!function_exists('flash_messages')) {
    function flash_messages(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $messages;
    }
}

if (!function_exists('back_with_errors')) {
    /** Redirect back with field errors and previously submitted input. */
    function back_with_errors(string $path, array $errors, array $input = []): never
    {
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = $input;
        redirect($path);
    }
}

if (!function_exists('errors')) {
    function errors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        return $errors;
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field, array $errors): string
    {
        return isset($errors[$field])
            ? '<p class="field-error">' . e($errors[$field]) . '</p>'
            : '';
    }
}

if (!function_exists('selected')) {
    function selected(mixed $a, mixed $b): string
    {
        return (string) $a === (string) $b ? 'selected' : '';
    }
}

if (!function_exists('checked')) {
    function checked(bool $condition): string
    {
        return $condition ? 'checked' : '';
    }
}

if (!function_exists('csrf_failed')) {
    function csrf_failed(): bool
    {
        return ($_GET['error'] ?? '') === 'csrf';
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('user_initials')) {
    function user_initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = mb_substr($parts[0], 0, 1);
        if (count($parts) > 1) {
            $initials .= mb_substr($parts[count($parts) - 1], 0, 1);
        }

        return mb_strtoupper($initials);
    }
}

if (!function_exists('dt')) {
    /** Format a stored UTC datetime in the configured timezone. */
    function dt(?string $value, string $format = 'd M Y, H:i'): string
    {
        if (!$value) {
            return '—';
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format($format);
        } catch (Throwable) {
            return '—';
        }
    }
}

if (!function_exists('ago')) {
    function ago(?string $value): string
    {
        if (!$value) {
            return 'never';
        }

        try {
            $ts = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return 'never';
        }

        $diff = time() - $ts;
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }

        return floor($diff / 86400) . 'd ago';
    }
}

if (!function_exists('status_label')) {
    function status_label(string $status): string
    {
        return match ($status) {
            'online'  => 'Online',
            'offline' => 'Offline',
            default   => 'Unknown',
        };
    }
}
