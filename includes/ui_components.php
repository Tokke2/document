<?php
declare(strict_types=1);

/**
 * Small, escaped presentation helpers shared by the portal tabs.
 * Keep these functions free of data access and request side effects.
 */

if (!function_exists('portal_ui_alert')) {
    function portal_ui_alert(string $message, string $tone = 'info', ?string $title = null, bool $assertive = false): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        $tone = in_array($tone, ['info', 'success', 'warning', 'error'], true) ? $tone : 'info';
        $icons = ['info' => 'i', 'success' => '✓', 'warning' => '!', 'error' => '×'];
        $role = ($tone === 'error' || $assertive) ? 'alert' : 'status';
        $live = ($tone === 'error' || $assertive) ? 'assertive' : 'polite';
        $titleMarkup = is_string($title) && trim($title) !== ''
            ? '<strong class="app-alert-title">' . h(trim($title)) . '</strong>'
            : '';

        return '<div class="app-alert app-alert-' . $tone . '" role="' . $role . '" aria-live="' . $live . '">'
            . '<span class="app-alert-icon" aria-hidden="true">' . $icons[$tone] . '</span>'
            . '<span class="app-alert-content">' . $titleMarkup . '<span>' . h($message) . '</span></span>'
            . '</div>';
    }
}

if (!function_exists('portal_ui_badge')) {
    function portal_ui_badge(string $label, string $tone = 'muted'): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }
        $tone = in_array($tone, ['info', 'success', 'warning', 'error', 'muted'], true) ? $tone : 'muted';
        $icons = ['info' => 'i', 'success' => '✓', 'warning' => '!', 'error' => '×', 'muted' => '•'];

        return '<span class="ui-badge ui-badge--' . $tone . '">'
            . '<span class="ui-badge__icon" aria-hidden="true">' . $icons[$tone] . '</span>'
            . '<span>' . h($label) . '</span>'
            . '</span>';
    }
}

if (!function_exists('portal_ui_breadcrumbs')) {
    /** @param list<array{label:string,href?:string}> $items */
    function portal_ui_breadcrumbs(array $items): string
    {
        $normalised = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['label']) || !is_string($item['label'])) {
                continue;
            }
            $label = trim($item['label']);
            if ($label === '') {
                continue;
            }
            $href = isset($item['href']) && is_string($item['href']) ? $item['href'] : '';
            // Breadcrumbs only link within the known portal tabs. Ignore any other URL.
            if (!preg_match('/\A\?tab=(?:protocol|invoice|skadeblankett|files|archive|customers|settings)\z/', $href)) {
                $href = '';
            }
            $normalised[] = ['label' => $label, 'href' => $href];
        }
        if ($normalised === []) {
            return '';
        }

        $last = count($normalised) - 1;
        $html = '<nav class="ui-breadcrumbs" aria-label="Brödsmulor"><ol>';
        foreach ($normalised as $index => $item) {
            $html .= '<li>';
            if ($index > 0) {
                $html .= '<span class="ui-breadcrumb-separator" aria-hidden="true">/</span>';
            }
            if ($index === $last) {
                $html .= '<span aria-current="page">' . h($item['label']) . '</span>';
            } elseif ($item['href'] !== '') {
                $html .= '<a href="' . h($item['href']) . '">' . h($item['label']) . '</a>';
            } else {
                $html .= '<span>' . h($item['label']) . '</span>';
            }
            $html .= '</li>';
        }
        return $html . '</ol></nav>';
    }
}
