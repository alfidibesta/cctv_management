<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Groups;
use Argus\Repositories\Layouts;
use Argus\Repositories\Users;
use Argus\Support\Activity;
use Argus\Support\Auth;

final class UserController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        view('pages/users', [
            'title'  => 'Users',
            'active' => 'users',
            'users'  => Users::all(),
            'groups' => Groups::all(),
            'errors' => errors(),
        ]);
    }

    public function store(): void
    {
        Auth::requireAdmin();

        $name = (string) $this->input('name', '');
        $email = mb_strtolower((string) $this->input('email', ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) $this->input('role', 'viewer');
        $groupIds = is_array($_POST['groups'] ?? null) ? $_POST['groups'] : [];
        $active = $this->bool('is_active');

        $error = $this->validate($name, $email, $role, $password, null);

        if ($error !== '') {
            flash($error, 'error');
            redirect('/users');
        }

        $id = Users::create([
            'name'      => mb_substr($name, 0, 120),
            'email'     => $email,
            'password'  => $password,
            'role'      => $role,
            'is_active' => $active ? 1 : 0,
        ], $groupIds);

        Activity::log('user.created', 'Created user ' . $email, 'user', $id);
        flash('User created.');

        redirect('/users');
    }

    public function update(string $id): void
    {
        Auth::requireAdmin();
        $user = Users::find((int) $id);

        if (!$user) {
            flash('User not found.', 'error');
            redirect('/users');
        }

        $name = (string) $this->input('name', '');
        $email = mb_strtolower((string) $this->input('email', ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) $this->input('role', 'viewer');
        $groupIds = is_array($_POST['groups'] ?? null) ? $_POST['groups'] : [];
        $active = $this->bool('is_active') ? 1 : 0;

        $error = $this->validate($name, $email, $role, $password, (int) $id);

        if ($error !== '') {
            flash($error, 'error');
            redirect('/users');
        }

        // Never leave the system without an active administrator.
        $wasAdmin = $user['role'] === 'admin' && (int) $user['is_active'] === 1;
        $staysAdmin = $role === 'admin' && $active === 1;

        if ($wasAdmin && !$staysAdmin && Users::countAdmins() <= 1) {
            flash('At least one active administrator is required.', 'error');
            redirect('/users');
        }

        Users::update((int) $id, [
            'name'      => mb_substr($name, 0, 120),
            'email'     => $email,
            'role'      => $role,
            'password'  => $password,
            'is_active' => $active,
        ], $groupIds);

        Activity::log('user.updated', 'Updated user ' . $email, 'user', (int) $id);
        flash('User updated.');

        redirect('/users');
    }

    public function delete(string $id): void
    {
        Auth::requireAdmin();
        $user = Users::find((int) $id);

        if (!$user) {
            flash('User not found.', 'error');
            redirect('/users');
        }

        if ((int) $user['id'] === (int) Auth::id()) {
            flash('You cannot delete your own account.', 'error');
            redirect('/users');
        }

        if ($user['role'] === 'admin' && (int) $user['is_active'] === 1 && Users::countAdmins() <= 1) {
            flash('At least one active administrator is required.', 'error');
            redirect('/users');
        }

        Users::delete((int) $id);
        Layouts::deleteForUser((int) $id);
        Activity::log('user.deleted', 'Deleted user ' . $user['email'], 'user', (int) $id);
        flash('User removed.');

        redirect('/users');
    }

    private function validate(string $name, string $email, string $role, string $password, ?int $ignoreId): string
    {
        if ($name === '') {
            return 'Enter a name for the user.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }

        if (Users::emailTaken($email, $ignoreId)) {
            return 'That email address is already in use.';
        }

        if (!in_array($role, Users::ROLES, true)) {
            return 'Choose a valid role.';
        }

        if ($ignoreId === null && mb_strlen($password) < 8) {
            return 'The password must be at least 8 characters.';
        }

        if ($ignoreId !== null && $password !== '' && mb_strlen($password) < 8) {
            return 'The new password must be at least 8 characters.';
        }

        return '';
    }
}
