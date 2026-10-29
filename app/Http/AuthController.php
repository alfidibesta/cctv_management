<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Support\Auth;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('/');
        }

        view('pages/login', [
            'title'  => 'Sign in',
            'errors' => errors(),
        ], 'layouts/guest');
    }

    public function login(): void
    {
        $email = (string) $this->input('email', '');
        $password = (string) ($_POST['password'] ?? '');

        $result = Auth::attempt($email, $password);

        if (!$result['ok']) {
            $_SESSION['_errors'] = ['email' => $result['error'] ?? 'Unable to sign in.'];
            $_SESSION['_old'] = ['email' => $email];
            redirect('/login');
        }

        $intended = (string) ($_SESSION['_intended'] ?? '/');
        unset($_SESSION['_intended']);

        if ($intended === '' || $intended[0] !== '/' || str_starts_with($intended, '//')) {
            $intended = '/';
        }

        redirect($intended);
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
