<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/self_registration.php';
require_once __DIR__ . '/../app/session_ui.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!is_string($requestMethod)) {
    $requestMethod = '';
}
$error = null;
$policy = null;
$flash = $_SESSION['estab_self_registration_flash'] ?? null;
unset($_SESSION['estab_self_registration_flash']);

if ($requestMethod === 'POST') {
    $connection = null;
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['admin_action'] ?? null;
        if (
            !is_string($action)
            || !in_array(
                $action,
                ['disable', 'enable_permanent', 'enable_temporary'],
                true
            )
        ) {
            throw new EstabSelfRegistrationInputException(
                'Unbekannte administrative Aktion.'
            );
        }
        if (
            $action !== 'disable'
            && ($_POST['confirm_activation'] ?? null) !== '1'
        ) {
            throw new EstabSelfRegistrationInputException(
                'Bestätigen Sie vor der Aktivierung den kontrollierten Zugang zur Anmeldeseite.'
            );
        }

        $mode = match ($action) {
            'disable' => ESTAB_SELF_REGISTRATION_MODE_DISABLED,
            'enable_permanent' => ESTAB_SELF_REGISTRATION_MODE_PERMANENT,
            'enable_temporary' => ESTAB_SELF_REGISTRATION_MODE_UNTIL,
        };
        $durationMinutes = $action === 'enable_temporary'
            ? estab_self_registration_duration_minutes(
                $_POST['duration_minutes'] ?? null
            )
            : null;
        $expectedRevision = estab_self_registration_revision(
            $_POST['expected_revision'] ?? null
        );

        $connection = estab_auth_connect($conf_4f_db);
        $result = estab_self_registration_update(
            $connection,
            (string) $conf_4f_db['datenbank'],
            $conf_4f_tbl['protokoll'],
            $mode,
            $durationMinutes,
            $expectedRevision,
            estab_self_registration_actor($_SERVER['REMOTE_USER'] ?? null),
            estab_auth_remote_ip($_SERVER)
        );
        $updatedPolicy = $result['policy'] ?? null;
        $message = !empty($result['changed'])
            ? match ($action) {
                'disable' => 'Die Selbstregistrierung ist jetzt deaktiviert.',
                'enable_permanent' => 'Die Selbstregistrierung ist jetzt dauerhaft aktiviert.',
                'enable_temporary' => 'Die Selbstregistrierung ist jetzt für den gewählten Zeitraum aktiviert.',
            }
            : 'Der gewünschte Zustand war bereits wirksam.';
        if (
            $action === 'enable_temporary'
            && is_array($updatedPolicy)
            && is_string($updatedPolicy['enabled_until_utc'] ?? null)
        ) {
            $message .= ' Die Freigabe endet automatisch; die genaue Endzeit '
                . 'wird auf der Statuskarte angezeigt.';
        }
        $_SESSION['estab_self_registration_flash'] = $message;
        header('Location: self_registration.php', true, 303);
        exit;
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabSelfRegistrationInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabSelfRegistrationConflictException $exception) {
        http_response_code(409);
        $error = $exception->getMessage()
            . ' Prüfen Sie den aktuellen Stand und versuchen Sie es erneut.';
    } catch (EstabSelfRegistrationBusyException $exception) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log(
            'eStab self-registration administration failed: '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Die Selbstregistrierung konnte nicht vollständig geändert '
            . 'werden. Details stehen im Container-Log.';
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
} elseif ($requestMethod !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    $error = 'Diese Seite unterstützt nur GET und POST.';
}

$connection = null;
try {
    $connection = estab_auth_connect($conf_4f_db);
    $policy = estab_self_registration_status(
        estab_self_registration_load($connection)
    );
} catch (Throwable $exception) {
    error_log(
        'eStab self-registration policy load failed: '
        . $exception->getMessage()
    );
    if ($error === null) {
        http_response_code(503);
        $error = 'Der aktuelle Zustand der Selbstregistrierung konnte '
            . 'nicht geladen werden. Die öffentliche Kontoanlage bleibt '
            . 'aus Sicherheitsgründen geschlossen.';
    }
} finally {
    if ($connection instanceof mysqli) {
        estab_auth_close($connection);
    }
}

$durationLabels = [
    15 => '15 Minuten',
    30 => '30 Minuten',
    60 => '1 Stunde',
    120 => '2 Stunden',
    240 => '4 Stunden',
    480 => '8 Stunden',
    720 => '12 Stunden',
    1440 => '24 Stunden',
];
$statusLabel = 'Nicht verfügbar';
$statusDescription = 'Die öffentliche Kontoanlage ist geschlossen.';
$statusClass = 'estab-tool-status-danger';
$deadlineLocal = null;
$updatedLocal = null;
$remainingText = null;
$statusRefreshMilliseconds = null;

if (is_array($policy)) {
    $state = (string) ($policy['state'] ?? '');
    $effective = !empty($policy['effective']);
    $statusClass = $effective
        ? 'estab-tool-status-active'
        : 'estab-tool-status-danger';
    [$statusLabel, $statusDescription] = match ($state) {
        ESTAB_SELF_REGISTRATION_MODE_PERMANENT => [
            'Dauerhaft aktiviert',
            'Neue Funktionskonten können ohne zeitliche Begrenzung angelegt werden.',
        ],
        ESTAB_SELF_REGISTRATION_MODE_UNTIL => [
            'Befristet aktiviert',
            'Neue Funktionskonten können bis zur angezeigten Endzeit angelegt werden.',
        ],
        'EXPIRED' => [
            'Deaktiviert · Freigabe abgelaufen',
            'Die befristete Freigabe ist automatisch beendet. Bereits bestehende Konten bleiben unverändert.',
        ],
        'ENVIRONMENT_ENABLED' => [
            'Aktiv · bisherige Installationsvorgabe',
            'Dieser Upgrade-Startzustand folgt noch der bisherigen Container-Einstellung. Die nächste administrative Auswahl ersetzt sie dauerhaft.',
        ],
        'ENVIRONMENT_DISABLED' => [
            'Deaktiviert · bisherige Installationsvorgabe',
            'Dieser Upgrade-Startzustand folgt noch der bisherigen Container-Einstellung. Die nächste administrative Auswahl ersetzt sie dauerhaft.',
        ],
        default => [
            'Deaktiviert',
            'Neue Funktionskonten können nicht öffentlich angelegt werden.',
        ],
    };

    try {
        $localTimezone = new DateTimeZone(date_default_timezone_get());
        if (is_string($policy['enabled_until_utc'] ?? null)) {
            $deadlineUtc = estab_self_registration_datetime(
                $policy['enabled_until_utc'],
                'Die Endzeit der Selbstregistrierung'
            );
            $deadlineLocal = $deadlineUtc
                ->setTimezone($localTimezone)
                ->format('d.m.Y H:i:s T');
            if (
                $effective
                && $state === ESTAB_SELF_REGISTRATION_MODE_UNTIL
                && is_string($policy['current_utc'] ?? null)
            ) {
                $currentUtc = estab_self_registration_datetime(
                    $policy['current_utc'],
                    'Die Datenbankzeit der Selbstregistrierung'
                );
                $deltaMicroseconds = (
                    ((int) $deadlineUtc->format('U')
                        - (int) $currentUtc->format('U')) * 1_000_000
                ) + (
                    (int) $deadlineUtc->format('u')
                    - (int) $currentUtc->format('u')
                );
                if ($deltaMicroseconds > 0) {
                    $refreshMilliseconds = intdiv(
                        $deltaMicroseconds + 999,
                        1000
                    ) + 250;
                    $maximumRefreshMilliseconds = (
                        max(ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS)
                        * 60 * 1000
                    ) + 1000;
                    $statusRefreshMilliseconds = min(
                        $refreshMilliseconds,
                        $maximumRefreshMilliseconds
                    );
                }
            }
        }
        if (is_string($policy['updated_at'] ?? null)) {
            $updatedLocal = estab_self_registration_datetime(
                $policy['updated_at'],
                'Der Änderungszeitpunkt der Selbstregistrierung'
            )->setTimezone($localTimezone)->format('d.m.Y H:i:s T');
        }
    } catch (Throwable $exception) {
        error_log(
            'eStab self-registration display time failed: '
            . $exception->getMessage()
        );
    }

    if ($effective && is_int($policy['remaining_seconds'] ?? null)) {
        $remainingSeconds = (int) $policy['remaining_seconds'];
        $hours = intdiv($remainingSeconds, 3600);
        $minutes = intdiv($remainingSeconds % 3600, 60);
        $remainingText = $hours > 0
            ? $hours . ' Std. ' . $minutes . ' Min.'
            : max(1, $minutes) . ' Min.';
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Selbstregistrierung</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main" data-estab-self-registration-admin
    <?php if ($statusRefreshMilliseconds !== null): ?>
      data-estab-self-registration-refresh-ms="<?= $statusRefreshMilliseconds ?>"
    <?php endif; ?>>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Technischer Administrationszugang</p>
      <h1>Selbstregistrierung</h1>
      <p>Steuern Sie, ob Personen auf der Anmeldeseite selbst ein neues
        Funktionskonto anlegen dürfen. Bestehende Konten und aktive Sitzungen
        werden durch diese Einstellung nicht verändert.</p>
    </header>

    <aside class="estab-tool-notice" aria-label="Sicherheitshinweis">
      <strong>Nur in einem kontrollierten Netz und unter Aufsicht öffnen.</strong>
      <p>Jede Person, die während der Freigabe die Anmeldeseite erreicht,
        kann selbst ein Konto für jede dort angebotene aktive Funktion
        anlegen – auch für Funktionen mit weitreichenden Fachrechten.</p>
      <p>Die serverseitige Prüfung gilt auch für bereits geöffnete Formulare.
        Läuft eine Freigabe vor dem Absenden ab oder wird sie vorzeitig
        deaktiviert, wird kein Konto angelegt.</p>
    </aside>

    <?php if (is_string($flash) && $flash !== ''): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        <?= estab_auth_html($flash) ?>
      </p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_auth_html($error) ?>
      </p>
    <?php endif; ?>

    <section class="estab-tool-status <?= estab_auth_html($statusClass) ?>"
      aria-labelledby="self-registration-status-title">
      <div>
        <strong id="self-registration-status-title">
          <?= estab_auth_html($statusLabel) ?>
        </strong>
        <span><?= estab_auth_html($statusDescription) ?></span>
        <?php if ($deadlineLocal !== null): ?>
          <span><strong>Automatisches Ende:</strong>
            <?= estab_auth_html($deadlineLocal) ?>
            <?php if ($remainingText !== null): ?>
              · noch etwa <?= estab_auth_html($remainingText) ?>
            <?php endif; ?>
          </span>
        <?php endif; ?>
      </div>
    </section>

    <?php if (is_array($policy)): ?>
      <section class="estab-tool-panel" aria-labelledby="temporary-title">
        <div class="estab-tool-panel-heading">
          <h2 id="temporary-title">Befristet aktivieren</h2>
          <p>Der Zeitraum beginnt beim Klick auf „Jetzt aktivieren“. Nach dem
            Ende schließt die Kontoanlage automatisch; ein Hintergrunddienst
            oder späteres manuelles Abschalten ist nicht erforderlich.</p>
        </div>
        <form method="post" action="self_registration.php"
          class="estab-tool-form" autocomplete="off">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="enable_temporary">
          <input type="hidden" name="expected_revision"
            value="<?= (int) $policy['revision'] ?>">
          <label>
            Freigabe ab jetzt
            <select name="duration_minutes" required>
              <?php foreach (ESTAB_SELF_REGISTRATION_ALLOWED_DURATIONS as $minutes): ?>
                <option value="<?= (int) $minutes ?>"
                  <?= $minutes === 60 ? 'selected' : '' ?>>
                  <?= estab_auth_html($durationLabels[$minutes] ?? ($minutes . ' Minuten')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="estab-tool-check">
            <input type="checkbox" name="confirm_activation" value="1"
              required>
            <span>Ich bestätige, dass nur die vorgesehenen Personen die
              Anmeldeseite erreichen und die Kontoanlagen beaufsichtigt
              werden.</span>
          </label>
          <button class="estab-button estab-button-primary" type="submit">
            Jetzt befristet aktivieren
          </button>
        </form>
      </section>

      <section class="estab-tool-panel" aria-labelledby="direct-actions-title">
        <div class="estab-tool-panel-heading">
          <h2 id="direct-actions-title">Direkte Steuerung</h2>
          <p>„Jetzt deaktivieren“ beendet auch eine laufende befristete
            Freigabe. „Dauerhaft aktivieren“ lässt die öffentliche Kontoanlage
            offen, bis sie hier ausdrücklich wieder deaktiviert wird.</p>
        </div>
        <div class="estab-tool-actions">
          <form method="post" action="self_registration.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action" value="disable">
            <input type="hidden" name="expected_revision"
              value="<?= (int) $policy['revision'] ?>">
            <button class="estab-button estab-button-danger" type="submit">
              Jetzt deaktivieren
            </button>
          </form>
          <form method="post" action="self_registration.php">
            <?= estab_csrf_field() ?>
            <input type="hidden" name="admin_action" value="enable_permanent">
            <input type="hidden" name="expected_revision"
              value="<?= (int) $policy['revision'] ?>">
            <label class="estab-tool-check">
              <input type="checkbox" name="confirm_activation" value="1"
                required>
              <span>Ich bestätige den kontrollierten und beaufsichtigten
                Zugang zur Kontoanlage.</span>
            </label>
            <button class="estab-button" type="submit">
              Dauerhaft aktivieren
            </button>
          </form>
        </div>
      </section>

      <section class="estab-tool-panel" aria-labelledby="policy-meta-title">
        <h2 id="policy-meta-title">Änderungsnachweis</h2>
        <p class="estab-tool-meta">Version <?= (int) $policy['revision'] ?> ·
          zuletzt geändert von
          <?= estab_auth_html((string) $policy['updated_by']) ?>
          <?php if ($updatedLocal !== null): ?>
            am <?= estab_auth_html($updatedLocal) ?>
          <?php endif; ?>
        </p>
        <p>Jede echte Änderung wird gemeinsam mit dem neuen Zustand, dem
          technischen Administrationsbenutzer und der validierten Quelladresse
          im Systemprotokoll gespeichert.</p>
      </section>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <a href="users.php">Zur Benutzerverwaltung</a>
      <a href="../">Zur eStab-Übersicht</a>
    </footer>
  </main>
  <?php if ($statusRefreshMilliseconds !== null): ?>
    <script<?= estab_csp_script_attribute() ?> data-estab-self-registration-expiry-refresh>
      (() => {
        const root = document.querySelector(
          '[data-estab-self-registration-refresh-ms]'
        );
        if (!(root instanceof HTMLElement)) {
          return;
        }
        const delay = Number(root.dataset.estabSelfRegistrationRefreshMs);
        const maximumDelay = 86401000;
        if (
          !Number.isSafeInteger(delay)
          || delay < 1
          || delay > maximumDelay
        ) {
          return;
        }

        const navigationEntry = performance.getEntriesByType('navigation')[0];
        const responseElapsed = (
          navigationEntry
          && Number.isFinite(navigationEntry.responseStart)
          && navigationEntry.responseStart > 0
        )
          ? Math.max(0, performance.now() - navigationEntry.responseStart)
          : 0;
        const remainingDelay = Math.max(
          1,
          delay - Math.ceil(responseElapsed)
        );
        const expiresAt = Date.now() + remainingDelay;
        let reloading = false;
        const reload = () => {
          if (reloading) {
            return;
          }
          reloading = true;
          window.location.replace('self_registration.php');
        };
        const reloadWhenExpired = () => {
          if (Date.now() >= expiresAt) {
            reload();
          }
        };

        window.setTimeout(reload, remainingDelay);
        window.addEventListener('focus', reloadWhenExpired);
        window.addEventListener('pageshow', reloadWhenExpired);
        document.addEventListener('visibilitychange', () => {
          if (document.visibilityState === 'visible') {
            reloadWhenExpired();
          }
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
