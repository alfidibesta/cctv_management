<?php

declare(strict_types=1);

namespace Argus\Http;

use Argus\Support\Activity;
use Argus\Support\Auth;

final class ActivityController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        view('pages/activity', [
            'title'    => 'Activity',
            'active'   => 'activity',
            'entries'  => Activity::recent(200),
        ]);
    }
}
