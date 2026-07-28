<?php

declare(strict_types=1);

define('showmenue', true);

require_once __DIR__ . '/app/session_ui.php';
require_once __DIR__ . '/app/root_menu.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
estab_session_ui_start($_SESSION);
$rootIdentity = estab_auth_session_identity($_SESSION);
$authenticated = $rootIdentity !== null;
$registrationAllowed = estab_auth_self_registration_allowed();

include __DIR__ . '/menue.inc.php';

$pageTitle = estab_auth_html((string) $conf_menue['titel']);
$organisation = estab_auth_html((string) $conf_menue['einrichtung']);
$background = estab_auth_html((string) $conf_menue['background_color']);
$foreground = estab_auth_html((string) $conf_menue['foreground_color']);
$leftSymbol = estab_auth_html((string) $conf_menue['sym_top_left']);
$rightSymbol = estab_auth_html((string) $conf_menue['sym_top_right']);
$loginUrl = estab_auth_html(
    estab_application_url('4fach/index.php?login_flow=existing')
);
$registrationUrl = estab_auth_html(
    estab_application_url('4fach/index.php?login_flow=new')
);
$applicationUrl = estab_auth_html(estab_application_url('4fach/index.php'));

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="shortcut icon" href="favicon.ico">
  <link rel="stylesheet" href="./estab-ui.css">
  <title><?= $pageTitle ?></title>
</head>
<body style="background-color:<?= $background ?>">
  <header class="estab-root-header" style="--estab-menu-background:<?= $foreground ?>">
    <img src="<?= $leftSymbol ?>" alt="Taktisches Zeichen Einsatzleitung">
    <p><?= $organisation ?></p>
    <img src="<?= $rightSymbol ?>" alt="Taktisches Zeichen Information und Kommunikation">
  </header>

  <main class="estab-root-main">
    <section class="estab-login-cta" aria-labelledby="estab-login-title">
<?php if (!$authenticated): ?>
      <h1 id="estab-login-title">eStab-Anmeldung</h1>
      <p>Verwenden Sie „Bestehendes Konto“, wenn Sie bereits Name, Kürzel und Kennwort erhalten oder früher ein Konto angelegt haben.</p>
      <div class="estab-auth-actions estab-root-auth-actions">
        <a id="estab-login" class="estab-button estab-button-primary" href="<?= $loginUrl ?>">Mit bestehendem Konto anmelden</a>
<?php if ($registrationAllowed): ?>
        <a id="estab-register" class="estab-button" href="<?= $registrationUrl ?>">Neues Konto anlegen</a>
<?php endif; ?>
      </div>
<?php if ($registrationAllowed): ?>
      <p class="estab-auth-note">„Neues Konto anlegen“ verwenden Sie nur, wenn für Ihr Kürzel noch kein Konto existiert und die zuständige Stelle die Registrierung freigegeben hat.</p>
<?php else: ?>
      <p class="estab-auth-note">Neue Konten können auf dieser Installation nicht selbst angelegt werden. Wenden Sie sich an die zuständige Stelle.</p>
<?php endif; ?>
<?php else: ?>
      <h1 id="estab-login-title">eStab öffnen</h1>
      <p>Ihre Anmeldung ist aktiv. Öffnen Sie den Nachrichtenvordruck oder wählen Sie einen der jetzt freigeschalteten Bereiche.</p>
      <p><a id="estab-open" class="estab-button estab-button-primary" href="<?= $applicationUrl ?>">Zum Nachrichtenvordruck</a></p>
<?php endif; ?>
      <p><small>Die Administration verwendet einen separaten technischen Zugang und ist kein eStab-Funktionskonto.</small></p>
    </section>

    <nav class="estab-menu-section" aria-labelledby="estab-modules-title">
      <h2 id="estab-modules-title">eStab-Bereiche</h2>
      <?= estab_root_menu_markup($menue, $authenticated) ?>
    </nav>

<?php if (showmenue): ?>
    <nav class="estab-menu-section" aria-labelledby="estab-more-title">
      <h2 id="estab-more-title">Administration und Hilfe</h2>
      <?= estab_root_menu_markup($zusatz_menue, $authenticated) ?>
    </nav>
<?php endif; ?>
  </main>
</body>
</html>
