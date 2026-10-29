<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Users;
use Argus\Support\Activity;
use Argus\Support\Auth;

final class AccountController extends Controller
{
    public function index(): void
    {
        view('pages/account', [
            'title'  => 'My account',
            'active' => 'account',
            'me'     => Auth::user(),
            'errors' => errors(),
            'old'    => $_SESSION['_old'] ?? [],
        ]);

        unset($_SESSION['_old']);
    }

    public function update(): void
    {
        $user = Auth::user();
        $name = (string) $this->input('name', '');
        $email = mb_strtolower((string) $this->input('email', ''));
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Enter your name.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } elseif (Users::emailTaken($email, (int) $user['id'])) {
            $errors['email'] = 'Another account already uses that email.';
        }

        $changingPassword = $password !== '';

        if ($changingPassword) {
            if (mb_strlen($password) < 8) {
                $errors['password'] = 'Use at least 8 characters.';
            }

            if ($current === '') {
                $errors['current_password'] = 'Confirm your current password.';
            } elseif (!password_verify($current, Users::passwordHash((int) $user['id']))) {
                $errors['current_password'] = 'That is not your current password.';
            }
        }

        if ($errors) {
            back_with_errors('/account', $errors, ['name' => $name, 'email' => $email]);
        }

        Users::update((int) $user['id'], [
            'name'      => mb_substr($name, 0, 120),
            'email'     => $email,
            'role'      => (string) $user['role'],
            'password'  => $changingPassword ? $password : '',
            'is_active' => 1,
        ], Users::groupIds((int) $user['id']));

        Activity::log('account.updated', 'Updated own account');
        flash('Your account was updated.');

        redirect('/account');
    }
}
