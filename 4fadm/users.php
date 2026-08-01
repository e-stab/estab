<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/session_ui.php';
require_once __DIR__ . '/../app/user_admin.php';

estab_admin_require_http_auth($_SERVER);
estab_session_ui_start($_SESSION);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($requestMethod)) {
    $requestMethod = '';
}
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
}

$flash = $_SESSION['estab_user_admin_flash'] ?? null;
unset($_SESSION['estab_user_admin_flash']);
$error = null;
$functionRoles = [];
$passwordPolicy = null;

if ($requestMethod === 'POST') {
    try {
        estab_csrf_require_post($_SERVER, $_POST);
    } catch (Throwable) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    }

    $actionValue = $_POST['admin_action'] ?? null;
    $action = is_string($actionValue) ? $actionValue : '';
    if (
        $error === null
        && !in_array(
            $action,
            ['create', 'reassign', 'block', 'unblock', 'reset_password'],
            true
        )
    ) {
        http_response_code(422);
        $error = 'Unbekannte administrative Aktion.';
    }

    $targetCode = '';
    if ($error === null) {
        try {
            $targetCode = estab_user_admin_validate_code(
                $_POST['target_code'] ?? null
            );
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
        }
    }

    if ($error === null) {
        $connection = null;
        $newPassword = null;
        try {
            $connection = estab_auth_connect($conf_4f_db);
            $actor = estab_user_admin_actor($_SERVER);
            $remoteAddress = estab_auth_remote_ip($_SERVER);
            if ($action === 'create') {
                $requestPasswordPolicy = estab_password_policy_load($connection);
                $newPassword = estab_user_admin_validate_password(
                    $_POST['new_password'] ?? null,
                    $_POST['new_password_confirmation'] ?? null,
                    $requestPasswordPolicy
                );
                unset(
                    $_POST['new_password'],
                    $_POST['new_password_confirmation']
                );
                $result = estab_user_admin_create_account(
                    $connection,
                    (string) $conf_4f_db['datenbank'],
                    $conf_4f_tbl['benutzer'],
                    $conf_4f_tbl['protokoll'],
                    $_POST['account_name'] ?? null,
                    $targetCode,
                    $_POST['assigned_function'] ?? null,
                    $newPassword,
                    $newPassword,
                    $conf_4f_tbl['empfmtx'],
                    $actor,
                    $remoteAddress
                );
                unset($newPassword);
                $flashAction = 'created';
            } elseif ($action === 'reassign') {
                $result = estab_user_admin_reassign(
                    $connection,
                    (string) $conf_4f_db['datenbank'],
                    $conf_4f_tbl['benutzer'],
                    $conf_4f_tbl['protokoll'],
                    $targetCode,
                    $_POST['assigned_function'] ?? null,
                    $conf_4f_tbl['empfmtx'],
                    $actor,
                    $remoteAddress
                );
                $flashAction = !($result['changed'] ?? false)
                    ? 'assignment_unchanged'
                    : 'reassigned';
            } elseif ($action === 'reset_password') {
                $requestPasswordPolicy = estab_password_policy_load($connection);
                $newPassword = estab_user_admin_validate_password(
                    $_POST['new_password'] ?? null,
                    $_POST['new_password_confirmation'] ?? null,
                    $requestPasswordPolicy
                );
                unset(
                    $_POST['new_password'],
                    $_POST['new_password_confirmation']
                );
                $result = estab_user_admin_reset_password(
                    $connection,
                    (string) $conf_4f_db['datenbank'],
                    $conf_4f_tbl['benutzer'],
                    $conf_4f_tbl['protokoll'],
                    $targetCode,
                    $newPassword,
                    $actor,
                    $remoteAddress
                );
                unset($newPassword);
                $flashAction = 'password_reset';
            } else {
                $result = estab_user_admin_set_blocked(
                    $connection,
                    (string) $conf_4f_db['datenbank'],
                    $conf_4f_tbl['benutzer'],
                    $conf_4f_tbl['protokoll'],
                    $targetCode,
                    $action === 'block',
                    $actor,
                    $remoteAddress
                );
                $flashAction = !($result['changed'] ?? false)
                    ? 'unchanged'
                    : ($action === 'block' ? 'blocked' : 'unblocked');
            }

            $_SESSION['estab_user_admin_flash'] = [
                'action' => $flashAction,
                'target' => $targetCode,
                'active_session_revoked' =>
                    (bool) ($result['active_session_revoked'] ?? false),
                'funktion' => is_string($result['funktion'] ?? null)
                    ? $result['funktion']
                    : '',
            ];
            header('Location: users.php', true, 303);
            exit;
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
        } catch (EstabUserAdminNotFoundException $exception) {
            http_response_code(404);
            $error = $exception->getMessage();
        } catch (EstabUserAdminConflictException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        } catch (EstabUserAdminBusyException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        } catch (EstabAssignmentBusyException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        } catch (EstabPasswordPolicyBusyException $exception) {
            http_response_code(409);
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log(
                'eStab user administration failed: '
                . $exception->getMessage()
            );
            http_response_code(500);
            $error = 'Die Benutzeränderung konnte nicht vollständig und '
                . 'atomar gespeichert werden. Details stehen im Container-Log.';
        } finally {
            unset(
                $newPassword,
                $_POST['new_password'],
                $_POST['new_password_confirmation']
            );
            if ($connection instanceof mysqli) {
                estab_auth_close($connection);
            }
        }
    }
}

$users = [];
try {
    $connection = estab_auth_connect($conf_4f_db);
    try {
        try {
            $passwordPolicy = estab_password_policy_load($connection);
        } catch (Throwable $exception) {
            error_log(
                'eStab user-administration password-policy load failed: '
                . $exception->getMessage()
            );
            if ($error === null) {
                http_response_code(503);
                $error = 'Die Kennwortrichtlinie konnte nicht geladen werden. '
                    . 'Kontoanlage und Kennwortreset sind vorübergehend gesperrt.';
            }
        }
        $displayPolicyLockName = estab_assignment_acquire_policy_lock(
            $connection,
            (string) $conf_4f_db['datenbank'],
            $conf_4f_tbl['empfmtx']
        );
        try {
            $functionRoles = estab_user_admin_function_roles(
                $connection,
                $conf_4f_tbl['empfmtx']
            );
            $users = estab_user_admin_list(
                $connection,
                $conf_4f_tbl['benutzer']
            );
        } finally {
            estab_assignment_release_policy_lock(
                $connection,
                $displayPolicyLockName
            );
        }
    } finally {
        estab_auth_close($connection);
    }
} catch (EstabAssignmentBusyException $exception) {
    http_response_code(409);
    if ($error === null) {
        $error = $exception->getMessage();
    }
} catch (Throwable $exception) {
    error_log(
        'eStab user-administration list failed: ' . $exception->getMessage()
    );
    if ($error === null) {
        http_response_code(500);
        $error = 'Die Benutzerliste konnte nicht gelesen werden.';
    }
}

$flashMessages = [
    'created' => 'Das Konto wurde mit einer festen Funktionszuordnung angelegt.',
    'reassigned' => 'Die Funktionszuordnung wurde geändert.',
    'assignment_unchanged' => 'Diese Funktionszuordnung war bereits gesetzt.',
    'blocked' => 'Das Konto wurde gesperrt.',
    'unblocked' => 'Das Konto wurde entsperrt. Die nächste Nutzung erfordert eine neue Anmeldung.',
    'password_reset' => 'Das Kennwort wurde sicher ersetzt. Die nächste Nutzung erfordert eine neue Anmeldung.',
    'unchanged' => 'Der gewünschte Kontostatus war bereits gesetzt; es wurde nichts verändert.',
];
$flashMessage = null;
if (
    is_array($flash)
    && isset($flash['action'])
    && is_string($flash['action'])
    && array_key_exists($flash['action'], $flashMessages)
) {
    $flashMessage = $flashMessages[$flash['action']];
    if (
        isset($flash['target'])
        && is_string($flash['target'])
        && preg_match('/\A[a-z0-9_]{1,6}\z/D', $flash['target']) === 1
    ) {
        $flashMessage .= ' Benutzerkürzel: ' . $flash['target'] . '.';
    }
    if (!empty($flash['active_session_revoked'])) {
        $flashMessage .= ' Eine aktive Sitzung wurde widerrufen.';
    }
    if (
        isset($flash['funktion'])
        && is_string($flash['funktion'])
        && preg_match('/\A(?:A\/W|[A-Za-z0-9_]+)\z/D', $flash['funktion']) === 1
    ) {
        $flashMessage .= ' Zugewiesene Funktion: ' . $flash['funktion'] . '.';
    }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Benutzerverwaltung</title>
  <?= estab_session_ui_stylesheet() ?>
  <script src="../estab-password-policy.js" defer></script>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main estab-tool-main-wide" data-estab-user-admin>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Technischer Administrationszugang</p>
      <h1>Benutzerverwaltung</h1>
      <p>Konten mit einer festen Funktion anlegen, administrativ neu zuweisen,
        sperren oder wieder freigeben und Kennwörter nach der zentralen
        Richtlinie sicher zurücksetzen. Alle Änderungen werden gemeinsam mit
        einem Audit-Eintrag gespeichert.</p>
    </header>

    <aside
      class="estab-tool-notice"
      aria-label="Auswirkung der Benutzeraktionen">
      <strong>Funktionen sind administrative Berechtigungen:</strong>
      <p>Benutzer können ihre Funktion beim Anmelden nicht selbst ändern. Beim
        Neuzuweisen, Sperren und Zurücksetzen eines Kennworts endet eine
        bestehende Anmeldung. Ein gesperrtes Konto bleibt bis zum
        ausdrücklichen Entsperren unbenutzbar. Entfernt die Empfängermatrix
        eine zugewiesene Funktion, wird das Konto hier als „Zuordnung nicht
        mehr gültig“ markiert und muss vor der nächsten Anmeldung neu
        zugewiesen werden. Ändert sich nur die Rolle einer weiterhin gültigen
        Funktion, übernimmt das Konto die neue Rolle automatisch und eine
        bestehende Anmeldung endet.</p>
    </aside>

    <?php if ($flashMessage !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-success" role="status">
        <?= estab_admin_html($flashMessage) ?>
      </p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="estab-tool-feedback estab-tool-feedback-error" role="alert">
        <?= estab_admin_html($error) ?>
      </p>
    <?php endif; ?>

    <section class="estab-tool-panel" aria-labelledby="password-policy-title">
      <h2 id="password-policy-title">Kennwortrichtlinie</h2>
      <?php if (is_array($passwordPolicy)): ?>
        <p id="estab-password-policy-requirements">
          <?= estab_admin_html(
              estab_password_policy_requirements_text($passwordPolicy)
          ) ?>
        </p>
        <p>Die Richtlinie gilt für neue Konten, Kennwortresets und eine
          gegebenenfalls aktivierte Selbstregistrierung. Bestehende Kennwörter
          bleiben gültig.</p>
        <a class="estab-button" href="password_policy.php">
          Kennwortrichtlinie konfigurieren
        </a>
      <?php else: ?>
        <p class="estab-tool-empty">Die Richtlinie ist nicht verfügbar.
          Kennwörter können deshalb derzeit nicht sicher gesetzt werden.</p>
      <?php endif; ?>
    </section>

    <section class="estab-tool-panel" aria-labelledby="estab-create-user-title">
      <h2 id="estab-create-user-title">Benutzerkonto anlegen</h2>
      <p>Vergeben Sie Identität, Startkennwort und die einzige Funktion, mit der
        sich das neue Konto anmelden darf. Eine spätere Änderung ist nur hier
        in der Administration möglich.</p>
      <?php if ($functionRoles === [] || !is_array($passwordPolicy)): ?>
        <p class="estab-tool-empty">Die verfügbaren Funktionen konnten nicht
          geladen werden oder die Kennwortrichtlinie ist nicht verfügbar.</p>
      <?php else: ?>
        <form method="post" action="users.php" autocomplete="off"
          data-estab-dirty-guard class="estab-tool-form">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="create">
          <div class="estab-tool-form-grid">
            <label>
              Name
              <input type="text" name="account_name" maxlength="50"
                autocomplete="off" required>
            </label>
            <label>
              Benutzerkürzel
              <input type="text" name="target_code" minlength="1" maxlength="6"
                pattern="[a-z0-9_]{1,6}" autocomplete="off" required>
            </label>
            <label>
              Zugewiesene Funktion
              <select name="assigned_function" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($functionRoles as $function => $role): ?>
                  <option value="<?= estab_admin_html($function) ?>">
                    <?= estab_admin_html($function . ' · ' . $role) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Startkennwort
              <input type="password" name="new_password"
                data-estab-password-minimum-codepoints="<?=
                    (int) $passwordPolicy['minimum_length'] ?>"
                maxlength="<?= ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH ?>"
                autocomplete="new-password"
                aria-describedby="estab-password-policy-requirements" required>
            </label>
            <label>
              Startkennwort wiederholen
              <input type="password" name="new_password_confirmation"
                data-estab-password-minimum-codepoints="<?=
                    (int) $passwordPolicy['minimum_length'] ?>"
                maxlength="<?= ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH ?>"
                autocomplete="new-password"
                aria-describedby="estab-password-policy-requirements" required>
            </label>
          </div>
          <button class="estab-button estab-button-primary" type="submit">
            Konto sicher anlegen
          </button>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($users === []): ?>
      <section class="estab-tool-panel">
        <p class="estab-tool-empty">Es sind keine Benutzerkonten vorhanden.</p>
      </section>
    <?php else: ?>
      <div class="estab-tool-table-wrap estab-tool-table-responsive">
        <table class="estab-tool-table">
          <caption class="estab-visually-hidden">
            Benutzerkonten mit Status und administrativen Aktionen
          </caption>
          <thead>
            <tr>
              <th scope="col">Benutzer</th>
              <th scope="col">Funktion</th>
              <th scope="col">Rolle</th>
              <th scope="col">Status</th>
              <th scope="col">Aktionen</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <?php
              $rawCode = is_string($user['kuerzel'] ?? null)
                  ? (string) $user['kuerzel']
                  : '';
              $manageable = true;
              try {
                  $code = estab_user_admin_validate_code($rawCode);
              } catch (InvalidArgumentException) {
                  $code = $rawCode;
                  $manageable = false;
              }
              $blocked = (int) ($user['estab_gesperrt'] ?? 0) === 1;
              $storedFunction = (string) ($user['funktion'] ?? '');
              $storedRole = (string) ($user['rolle'] ?? '');
              $assignmentCurrent = isset($functionRoles[$storedFunction])
                  && hash_equals(
                      (string) $functionRoles[$storedFunction],
                      $storedRole
                  );
              $orphaned = $manageable && !$assignmentCurrent;
              $presence = estab_auth_presence_state($user);
              $active = !$blocked && !$orphaned && $presence === 'online';
              $idle = !$blocked && !$orphaned && $presence === 'inactive';
              $statusClass = !$manageable || $blocked || $orphaned
                  ? 'blocked'
                  : ($active ? 'online' : ($idle ? 'idle' : 'offline'));
              $statusLabel = !$manageable
                  ? 'Ungültiges Legacy-Kürzel'
                  : (
                      $orphaned
                          ? (
                              $blocked
                                  ? 'Gesperrt · Zuordnung nicht mehr gültig'
                                  : 'Zuordnung nicht mehr gültig'
                          )
                          : ($blocked
                          ? 'Gesperrt'
                          : ($active
                              ? 'Aktiv'
                              : ($idle
                                  ? 'Inaktiv (seit mindestens 15 Minuten)'
                                  : 'Abgemeldet')))
                  );
            ?>
            <tr data-estab-user="<?= estab_admin_html($code) ?>"
              <?= $orphaned ? 'data-estab-assignment-orphaned' : '' ?>>
              <td data-label="Benutzer" class="estab-tool-identity">
                <strong><?= estab_admin_html($user['benutzer'] ?? '') ?></strong>
                <span>Kürzel <?= estab_admin_html($code) ?></span>
              </td>
              <td data-label="Funktion">
                <?= estab_admin_html($user['funktion'] ?? '') ?>
              </td>
              <td data-label="Rolle">
                <?= estab_admin_html($user['rolle'] ?? '') ?>
                <?php if ($orphaned): ?>
                  <span class="estab-tool-badge estab-tool-badge-danger">
                    Neu zuweisen
                  </span>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <span class="estab-tool-badge estab-tool-badge-<?= $statusClass === 'online' ? 'success' : ($statusClass === 'idle' ? 'warning' : ($statusClass === 'blocked' ? 'danger' : 'neutral')) ?>">
                  <?= $statusLabel ?>
                </span>
              </td>
              <td data-label="Aktionen">
                <div class="estab-tool-action-stack">
                  <?php if (!$manageable): ?>
                    <p>Dieses historische Kürzel entspricht nicht den
                      heutigen Anmeldegrenzen und kann hier nicht sicher
                      verändert werden. Prüfen Sie den Datensatz anhand eines
                      Backups.</p>
                  <?php elseif ($blocked): ?>
                    <details>
                      <summary>Konto entsperren</summary>
                      <p>Das Konto kann sich danach mit seinem vorhandenen
                        Kennwort wieder anmelden.</p>
                      <form method="post" action="users.php">
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action" value="unblock">
                        <input type="hidden" name="target_code"
                          value="<?= estab_admin_html($code) ?>">
                        <button
                          class="estab-button estab-button-primary"
                          type="submit">
                          Konto entsperren
                        </button>
                      </form>
                    </details>
                  <?php else: ?>
                    <details>
                      <summary>Konto sperren</summary>
                      <p>Das Konto kann sich nicht mehr anmelden. Eine
                        bestehende Sitzung wird sofort widerrufen.</p>
                      <form method="post" action="users.php">
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action" value="block">
                        <input type="hidden" name="target_code"
                          value="<?= estab_admin_html($code) ?>">
                        <button class="estab-button estab-button-danger" type="submit">
                          Sperren und abmelden
                        </button>
                      </form>
                    </details>
                  <?php endif; ?>

                  <?php if ($manageable && $functionRoles !== []): ?>
                    <details>
                      <summary>Funktion neu zuweisen</summary>
                      <p>Die neue Funktion ersetzt die bisherige Berechtigung.
                        Eine aktive Sitzung wird sofort widerrufen.</p>
                      <form method="post" action="users.php"
                        data-estab-dirty-guard>
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action"
                          value="reassign">
                        <input type="hidden" name="target_code"
                          value="<?= estab_admin_html($code) ?>">
                        <label>
                          Zugewiesene Funktion
                          <select name="assigned_function" required>
                            <?php if ($orphaned): ?>
                              <option value="" selected disabled>
                                Neue Funktion wählen
                              </option>
                            <?php endif; ?>
                            <?php foreach ($functionRoles as $function => $role): ?>
                              <option
                                value="<?= estab_admin_html($function) ?>"
                                <?= hash_equals(
                                    (string) ($user['funktion'] ?? ''),
                                    $function
                                ) ? 'selected' : '' ?>>
                                <?= estab_admin_html(
                                    $function . ' · ' . $role
                                ) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <button class="estab-button estab-button-primary"
                          type="submit">
                          Funktion zuweisen und abmelden
                        </button>
                      </form>
                    </details>
                  <?php endif; ?>

                  <?php if ($manageable && is_array($passwordPolicy)): ?>
                    <details>
                      <summary>Kennwort zurücksetzen</summary>
                      <?php if ($orphaned): ?>
                        <p>Der Reset ist möglich, die Anmeldung bleibt aber
                          bis zur Zuweisung einer gültigen Funktion gesperrt.</p>
                      <?php endif; ?>
                      <p><?= estab_admin_html(
                          estab_password_policy_requirements_text(
                              $passwordPolicy
                          )
                      ) ?> Das bisherige Kennwort und eine aktive Sitzung
                        werden unmittelbar ungültig. Der Sperrstatus des Kontos
                        bleibt unverändert.</p>
                      <form method="post" action="users.php"
                        autocomplete="off" data-estab-dirty-guard>
                        <?= estab_csrf_field() ?>
                        <input type="hidden" name="admin_action"
                          value="reset_password">
                        <input type="hidden" name="target_code"
                          value="<?= estab_admin_html($code) ?>">
                        <label>
                          Neues Kennwort
                          <input type="password" name="new_password"
                            data-estab-password-minimum-codepoints="<?=
                                (int) $passwordPolicy['minimum_length'] ?>"
                            maxlength="<?= ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH ?>"
                            autocomplete="new-password"
                            aria-describedby="estab-password-policy-requirements"
                            required>
                        </label>
                        <label>
                          Neues Kennwort wiederholen
                          <input type="password" name="new_password_confirmation"
                            data-estab-password-minimum-codepoints="<?=
                                (int) $passwordPolicy['minimum_length'] ?>"
                            maxlength="<?= ESTAB_AUTH_PASSWORD_INPUT_MAXIMUM_LENGTH ?>"
                            autocomplete="new-password"
                            aria-describedby="estab-password-policy-requirements"
                            required>
                        </label>
                        <button class="estab-button estab-button-danger" type="submit">
                          Kennwort ersetzen und abmelden
                        </button>
                      </form>
                    </details>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <span>Kennwörter und Sitzungskennungen werden in dieser Übersicht nie angezeigt.</span>
    </footer>
  </main>
</body>
</html>
