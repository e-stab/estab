<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Convert the trusted legacy menu text into escaped, readable plain text. */
function estab_root_menu_text(mixed $value): string
{
    if (!is_string($value)) {
        return '';
    }
    $value = preg_replace('~<br\s*/?>~i', "\n", $value);
    $value = is_string($value) ? strip_tags($value) : '';
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[ \t]+/', ' ', $value);
    $value = preg_replace('/\s*\n\s*/', "\n", is_string($value) ? $value : '');
    return trim(is_string($value) ? $value : '');
}

/** Render one visible root-menu card with a single keyboard tab stop. */
function estab_root_menu_item_markup(array $item, bool $authenticated): string
{
    if (($item['visible'] ?? false) !== true) {
        return '';
    }

    $configuredLink = $item['link'] ?? null;
    $picture = $item['pic'] ?? null;
    $label = estab_root_menu_text($item['text'] ?? '');
    $information = estab_root_menu_text($item['info'] ?? '');
    if (
        !is_string($configuredLink)
        || $configuredLink === ''
        || !is_string($picture)
        || $picture === ''
        || $label === ''
    ) {
        return '';
    }

    $access = $item['access'] ?? 'public';
    if (!is_string($access) || !in_array(
        $access,
        ['application', 'administration', 'public'],
        true
    )) {
        throw new InvalidArgumentException('Unknown root-menu access class');
    }

    $locked = $access === 'application' && !$authenticated;
    $href = $locked
        ? estab_application_url('4fach/index.php')
        : $configuredLink;
    $target = $locked ? '' : ' target="_blank" rel="noopener noreferrer"';
    $cardClass = 'estab-menu-card estab-menu-card-' . $access
        . ($locked ? ' estab-menu-card-locked' : '');
    $badge = match (true) {
        $locked => 'Anmeldung erforderlich',
        $access === 'administration' => 'Separater Administrationszugang',
        default => '',
    };
    $linkTitle = $locked
        ? 'Zuerst mit einem eStab-Funktionskonto anmelden'
        : $information;

    $description = $information === ''
        ? ''
        : '<span class="estab-menu-description">'
            . nl2br(estab_auth_html($information), false)
            . '</span>';
    $badgeMarkup = $badge === ''
        ? ''
        : '<span class="estab-menu-badge">' . estab_auth_html($badge) . '</span>';

    return '<li class="' . $cardClass . '">'
        . '<a class="estab-menu-link" href="' . estab_auth_html($href) . '"'
        . $target . ' title="' . estab_auth_html($linkTitle) . '">'
        . '<span class="estab-menu-icon" aria-hidden="true">'
        . '<img src="' . estab_auth_html($picture) . '" alt=""></span>'
        . '<span class="estab-menu-copy">'
        . '<span class="estab-menu-title">'
        . nl2br(estab_auth_html($label), false)
        . '</span>'
        . $description
        . $badgeMarkup
        . '</span></a></li>';
}

/** Render a complete, valid menu grid and omit invalid or hidden entries. */
function estab_root_menu_markup(array $items, bool $authenticated): string
{
    $cards = '';
    ksort($items, SORT_NUMERIC);
    foreach ($items as $item) {
        if (is_array($item)) {
            $cards .= estab_root_menu_item_markup($item, $authenticated);
        }
    }

    return '<ul class="estab-root-menu">' . $cards . '</ul>';
}
