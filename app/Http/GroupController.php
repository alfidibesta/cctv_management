<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Repositories\Groups;
use Argus\Support\Activity;
use Argus\Support\Auth;

final class GroupController extends Controller
{
    public function index(): void
    {
        Auth::requireCameraManager();

        view('pages/groups', [
            'title'  => 'Groups',
            'active' => 'groups',
            'groups' => Groups::all(),
            'errors' => errors(),
        ]);
    }

    public function store(): void
    {
        Auth::requireCameraManager();

        $name = (string) $this->input('name', '');
        $description = (string) $this->input('description', '');

        if ($name === '') {
            flash('Group name is required.', 'error');
            redirect('/groups');
        }

        if (Groups::exists($name)) {
            flash('A group with that name already exists.', 'error');
            redirect('/groups');
        }

        $id = Groups::create(mb_substr($name, 0, 120), mb_substr($description, 0, 255));
        Activity::log('group.created', 'Created group "' . $name . '"', 'group', $id);
        flash('Group created.');

        redirect('/groups');
    }

    public function update(string $id): void
    {
        Auth::requireCameraManager();
        $group = Groups::find((int) $id);

        if (!$group) {
            flash('Group not found.', 'error');
            redirect('/groups');
        }

        $name = (string) $this->input('name', '');
        if ($name === '') {
            flash('Group name is required.', 'error');
            redirect('/groups');
        }

        if (Groups::exists($name, (int) $id)) {
            flash('Another group already uses that name.', 'error');
            redirect('/groups');
        }

        Groups::update((int) $id, mb_substr($name, 0, 120), mb_substr((string) $this->input('description', ''), 0, 255));
        Activity::log('group.updated', 'Updated group "' . $name . '"', 'group', (int) $id);
        flash('Group updated.');

        redirect('/groups');
    }

    public function delete(string $id): void
    {
        Auth::requireCameraManager();
        $group = Groups::find((int) $id);

        if (!$group) {
            flash('Group not found.', 'error');
            redirect('/groups');
        }

        Groups::delete((int) $id);
        Activity::log('group.deleted', 'Deleted group "' . $group['name'] . '"', 'group', (int) $id);
        flash('Group removed. Its cameras are now ungrouped.');

        redirect('/groups');
    }
}
