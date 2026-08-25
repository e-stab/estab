<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/navigation.php';

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

/**
 * Render one visible root-menu card with a single keyboard tab stop.
 *
 * Der geuebte Nutzer soll einen Bereich mit einer Taste erreichen. Die Ziffer
 * steht deshalb sichtbar an der Kachel und als Merkmal am Link; das Lagebild
 * liest sie aus. Ohne Ziffer bleibt die Kachel unveraendert.
 */
function estab_root_menu_item_markup(
    array $item,
    bool $authenticated,
    ?int $shortcut = null,
    ?array $identity = null
): string {
    if (($item['visible'] ?? false) !== true) {
        return '';
    }
    if ($shortcut !== null && ($shortcut < 1 || $shortcut > 9)) {
        throw new InvalidArgumentException('Invalid root-menu shortcut');
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

    $navigationKey = $item['navigation_key'] ?? null;
    $navigationItem = $navigationKey === null
        ? null
        : estab_navigation_item_for_key($navigationKey);
    if (
        $navigationKey !== null
        && (
            $navigationItem === null
            || './' . $navigationItem['path'] !== $configuredLink
        )
    ) {
        throw new InvalidArgumentException('Invalid root-menu navigation key');
    }

    $locked = $access === 'application' && !$authenticated;
    /*
     * Die Kachel und der Menueintrag fuehren in denselben Bereich. Sperrt das
     * Menue, sperrt die Kachel -- sonst lernt der Bedienende zwei Regeln
     * statt einer und traut am Ende keiner von beiden. Der Grund ist
     * derselbe, damit er nicht zweimal formuliert und irgendwann
     * unterschiedlich gepflegt wird.
     */
    $blockedReason = ($authenticated && !$locked && $navigationItem !== null)
        ? estab_navigation_duty_access_reason($navigationItem, $identity)
        : '';
    $href = $locked
        ? estab_navigation_login_url(
            $navigationItem === null ? null : $navigationItem['key']
        )
        : $configuredLink;
    $cardClass = 'estab-menu-card estab-menu-card-' . $access
        . ($locked ? ' estab-menu-card-locked' : '')
        . ($blockedReason === '' ? '' : ' estab-menu-card-blocked');
    $badge = match (true) {
        $locked => 'Anmeldung erforderlich',
        $blockedReason !== '' => $blockedReason,
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
    $shortcutMarkup = $shortcut === null
        ? ''
        : '<kbd class="estab-menu-shortcut">' . $shortcut . '</kbd>';

    $navigationAttribute = $navigationItem === null
        ? ''
        : ' data-estab-nav-key="'
            . estab_auth_html($navigationItem['key']) . '"';
    $inner = '<span class="estab-menu-icon" aria-hidden="true">'
        . '<img src="' . estab_auth_html($picture) . '" alt=""></span>'
        . '<span class="estab-menu-copy">'
        . '<span class="estab-menu-title">'
        . $shortcutMarkup
        . nl2br(estab_auth_html($label), false)
        . '</span>'
        . $description
        . $badgeMarkup
        . '</span>';

    // Eine gesperrte Kachel traegt kein href und verbraucht keine Ziffer:
    // Eine sichtbare Taste, die nichts oeffnet, ist schlimmer als keine.
    if ($blockedReason !== '') {
        return '<li class="' . $cardClass . '">'
            . '<span class="estab-menu-link estab-menu-link-blocked"'
            . $navigationAttribute
            . ' aria-disabled="true"'
            . ' title="' . estab_auth_html($blockedReason) . '">'
            . $inner
            . '</span></li>';
    }

    return '<li class="' . $cardClass . '">'
        . '<a class="estab-menu-link" href="' . estab_auth_html($href) . '"'
        . $navigationAttribute
        . ($shortcut === null
            ? ''
            : ' data-estab-shortcut="' . $shortcut . '"')
        . ' title="' . estab_auth_html($linkTitle) . '">'
        . $inner
        . '</a></li>';
}

/**
 * Render a complete, valid menu grid and omit invalid or hidden entries.
 *
 * Eine Ziffer wird erst vergeben, wenn eine Kachel wirklich entsteht: eine
 * unsichtbare oder unvollstaendige Kachel darf keine Taste verbrauchen, sonst
 * zeigt die sichtbare Ziffer auf einen anderen Bereich.
 */
function estab_root_menu_markup(
    array $items,
    bool $authenticated,
    bool $shortcuts = false,
    ?array $identity = null
): string {
    $cards = '';
    $shortcut = 0;
    ksort($items, SORT_NUMERIC);
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $next = $shortcuts && $shortcut < 9 ? $shortcut + 1 : null;
        $card = estab_root_menu_item_markup(
            $item,
            $authenticated,
            $next,
            $identity
        );
        // Eine gesperrte Kachel verbraucht keine Ziffer: Die sichtbare Zahl
        // muss den Bereich oeffnen, auf den sie zeigt.
        if (
            $card !== ''
            && $next !== null
            && !str_contains($card, 'estab-menu-card-blocked')
        ) {
            $shortcut = $next;
        }
        $cards .= $card;
    }

    return '<ul class="estab-root-menu">' . $cards . '</ul>';
}
