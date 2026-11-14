<?php
/**
 * Inline SVG icon set. Stroke based, inherits currentColor.
 */
if (!function_exists('icon')) {
    function icon(string $name, string $class = ''): string
    {
        $paths = [
            'wall'       => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'camera'     => '<path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h9A1.5 1.5 0 0 1 15 8.5v7A1.5 1.5 0 0 1 13.5 17h-9A1.5 1.5 0 0 1 3 15.5z"/><path d="m15 11 4.5-2.5v7L15 13"/><circle cx="9" cy="12" r="2.4"/>',
            'groups'     => '<path d="M3 7.5A1.5 1.5 0 0 1 4.5 6h4l1.6 1.8H19.5A1.5 1.5 0 0 1 21 9.3v8.2a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5z"/>',
            'users'      => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16.5 5.4a3.2 3.2 0 0 1 0 5.2M18 20a5.5 5.5 0 0 0-2.4-4.5"/>',
            'people'     => '<circle cx="9" cy="7.5" r="2.8"/><path d="M4 19a5 5 0 0 1 10 0"/><path d="M16.5 8.5a2.4 2.4 0 0 1 0 4.4M18 19a4.6 4.6 0 0 0-2-3.7"/><path d="M3 4v16h18"/>',
            'person'     => '<circle cx="12" cy="6.5" r="2.6"/><path d="M7 20v-3.4a2 2 0 0 1 1.6-2l2-.4V10h2.8v4.2l2 .4a2 2 0 0 1 1.6 2V20"/>',
            'wire'       => '<circle cx="5" cy="17" r="1.8"/><circle cx="19" cy="7" r="1.8"/><path d="M6.4 15.7 17.6 8.3"/><path d="M9 8.5h-4v4M15 15.5h4v-4"/>',
            'zone'       => '<rect x="4" y="4" width="16" height="16" rx="2" stroke-dasharray="4 3"/><circle cx="12" cy="12" r="2.2"/>',
            'settings'   => '<path d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2.2" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="2.2" fill="currentColor" stroke="none"/><circle cx="8" cy="17" r="2.2" fill="currentColor" stroke="none"/>',
            'activity'   => '<path d="M3 12h3.5l2.5-7 3.5 14 2.5-7H21"/>',
            'logout'     => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 8l-4 4 4 4M6 12h9"/>',
            'plus'       => '<path d="M12 5v14M5 12h14"/>',
            'search'     => '<circle cx="11" cy="11" r="6.2"/><path d="m20 20-4.4-4.4"/>',
            'refresh'    => '<path d="M20 12a8 8 0 1 1-2.6-5.9"/><path d="M20 4v4.5h-4.5"/>',
            'expand'     => '<path d="M9 4H4v5M15 4h5v5M15 20h5v-5M9 20H4v-5"/>',
            'download'   => '<path d="M12 4v11"/><path d="m8 11 4 4 4-4"/><path d="M4 19h16"/>',
            'close'      => '<path d="M6 6l12 12M18 6 6 18"/>',
            'edit'       => '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="m14.5 5.5 4 4"/>',
            'trash'      => '<path d="M4 7h16"/><path d="M9 7V4.5h6V7"/><path d="M6.5 7 7.5 20h9l1-13"/><path d="M10.5 11v5M13.5 11v5"/>',
            'chevron-left'  => '<path d="m14 6-6 6 6 6"/>',
            'chevron-right' => '<path d="m10 6 6 6-6 6"/>',
            'chevron-down'  => '<path d="m6 10 6 6 6-6"/>',
            'pause'      => '<path d="M9 5v14M15 5v14"/>',
            'play'       => '<path d="m7 4 13 8-13 8z"/>',
            'lock'       => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
            'mail'       => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.6 6.6 8.4 6.2 8.4-6.2"/>',
            'user'       => '<circle cx="12" cy="8.5" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
            'shield'     => '<path d="m12 3 7.5 3v6c0 4.6-3.2 8.3-7.5 9.5C7.7 20.3 4.5 16.6 4.5 12V6z"/>',
            'info'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.4"/>',
            'check'      => '<path d="m5 12.5 4.6 4.5L19 6.5"/>',
            'menu'       => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'grid9'      => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M9.2 3.5v17M14.8 3.5v17M3.5 9.2h17M3.5 14.8h17"/>',
            'grid4'      => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M12 3.5v17M3.5 12h17"/>',
            'grid16'     => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M7.75 3.5v17M12 3.5v17M16.25 3.5v17M3.5 7.75h17M3.5 12h17M3.5 16.25h17"/>',
            'reset'      => '<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4.5h4.5"/><path d="M12 8v4.2l2.8 1.8"/>',
            'pip'        => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><rect x="12.5" y="11.5" width="6" height="6" rx="1.2"/>',
            'single'     => '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/>',
            'volume'     => '<path d="M4 10v4h3l4 3.5v-11L7 10z"/><path d="M15 9.5a3.5 3.5 0 0 1 0 5"/>',
            'clock'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
            'eye'        => '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
            'database'   => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
            'arrow-up'   => '<path d="M12 19V5M6 11l6-6 6 6"/>',
            'arrow-down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
            'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
            'arrow-right'=> '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'zoom-in'    => '<circle cx="11" cy="11" r="6.2"/><path d="m20 20-4.4-4.4M8.5 11h5M11 8.5v5"/>',
            'zoom-out'   => '<circle cx="11" cy="11" r="6.2"/><path d="m20 20-4.4-4.4M8.5 11h5"/>',
            'home'       => '<path d="m4 11 8-6.5 8 6.5"/><path d="M6.5 9.8V20h11V9.8"/>',
            'stop'       => '<rect x="6.5" y="6.5" width="11" height="11" rx="2"/>',
            'scan'       => '<path d="M4 8V5.5A1.5 1.5 0 0 1 5.5 4H8M16 4h2.5A1.5 1.5 0 0 1 20 5.5V8M20 16v2.5a1.5 1.5 0 0 1-1.5 1.5H16M8 20H5.5A1.5 1.5 0 0 1 4 18.5V16"/><path d="M7 12h10"/>',
            'filter'     => '<path d="M4 5h16l-6.3 7.4V19l-3.4 1.5v-8.1z"/>',
            'layers'     => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
            'panel'      => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M9.5 4.5v15"/>',
            'rows'       => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'sun'        => '<circle cx="12" cy="12" r="4.1"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4 17 7M7 17l-1.6 1.6"/>',
            'moon'       => '<path d="M20.2 14.2A8.4 8.4 0 0 1 9.8 3.8a8.4 8.4 0 1 0 10.4 10.4z"/>',
        ];

        $body = $paths[$name] ?? $paths['info'];

        return sprintf(
            '<svg class="icon %s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            e($class),
            $body
        );
    }
}
