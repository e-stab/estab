<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../4fcfg/dbcfg.inc.php';
require_once __DIR__ . '/../app/admin_operations.php';
require_once __DIR__ . '/../app/csrf.php';
require_once __DIR__ . '/../app/password_policy.php';
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
$preview = null;
$flash = $_SESSION['estab_password_policy_flash'] ?? null;
unset($_SESSION['estab_password_policy_flash']);

if ($requestMethod === 'POST') {
    $connection = null;
    try {
        estab_csrf_require_post($_SERVER, $_POST);
        $action = $_POST['admin_action'] ?? null;
        if (!is_string($action) || !in_array($action, ['preview', 'apply'], true)) {
            throw new EstabPasswordPolicyInputException(
                'Unbekannte administrative Aktion.'
            );
        }
        $configuration = estab_password_policy_configuration_from_request(
            $_POST
        );
        $expectedRevision = estab_password_policy_revision(
            $_POST['expected_revision'] ?? null
        );
        $connection = estab_auth_connect($conf_4f_db);
        if ($action === 'preview') {
            $policy = estab_password_policy_load($connection);
            if ((int) $policy['revision'] !== $expectedRevision) {
                throw new EstabPasswordPolicyConflictException(
                    'Die Kennwortrichtlinie wurde zwischenzeitlich geändert.'
                );
            }
            $preview = $configuration;
        } else {
            if (($_POST['confirm_policy'] ?? null) !== '1') {
                throw new EstabPasswordPolicyInputException(
                    'Bestätigen Sie die angezeigte Kennwortrichtlinie.'
                );
            }
            $result = estab_password_policy_update(
                $connection,
                (string) $conf_4f_db['datenbank'],
                $conf_4f_tbl['protokoll'],
                $configuration,
                $expectedRevision,
                estab_password_policy_actor($_SERVER['REMOTE_USER'] ?? null),
                estab_auth_remote_ip($_SERVER)
            );
            $_SESSION['estab_password_policy_flash'] = !empty($result['changed'])
                ? 'Die Kennwortrichtlinie wurde gespeichert. Bestehende '
                    . 'Kennwörter wurden nicht verändert.'
                : 'Die Kennwortrichtlinie war bereits unverändert gespeichert.';
            header('Location: password_policy.php', true, 303);
            exit;
        }
    } catch (EstabCsrfException) {
        http_response_code(403);
        $error = 'Die Formularsitzung ist ungültig oder abgelaufen. '
            . 'Bitte laden Sie die Seite neu.';
    } catch (EstabPasswordPolicyInputException $exception) {
        http_response_code(422);
        $error = $exception->getMessage();
    } catch (EstabPasswordPolicyConflictException $exception) {
        http_response_code(409);
        $error = $exception->getMessage()
            . ' Prüfen Sie den aktuellen Stand und versuchen Sie es erneut.';
    } catch (EstabPasswordPolicyBusyException $exception) {
        http_response_code(409);
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log(
            'eStab password-policy administration failed: '
            . $exception->getMessage()
        );
        http_response_code(500);
        $error = 'Die Kennwortrichtlinie konnte nicht vollständig verarbeitet '
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

if (!is_array($policy)) {
    $connection = null;
    try {
        $connection = estab_auth_connect($conf_4f_db);
        $policy = estab_password_policy_load($connection);
    } catch (Throwable $exception) {
        error_log('eStab password-policy load failed: ' . $exception->getMessage());
        if ($error === null) {
            http_response_code(503);
            $error = 'Die aktuelle Kennwortrichtlinie konnte nicht geladen werden.';
        }
    } finally {
        if ($connection instanceof mysqli) {
            estab_auth_close($connection);
        }
    }
}

$formPolicy = is_array($preview) ? $preview : $policy;
$weakened = false;
if (is_array($policy) && is_array($preview)) {
    $weakened = (int) $preview['minimum_length']
            < (int) $policy['minimum_length']
        || (!empty($policy['require_uppercase'])
            && empty($preview['require_uppercase']))
        || (!empty($policy['require_lowercase'])
            && empty($preview['require_lowercase']))
        || (!empty($policy['require_digit'])
            && empty($preview['require_digit']))
        || (!empty($policy['require_symbol'])
            && empty($preview['require_symbol']));
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>eStab Kennwortrichtlinie</title>
  <?= estab_session_ui_stylesheet() ?>
</head>
<body class="estab-tool-page">
  <main class="estab-tool-main" data-estab-password-policy>
    <header class="estab-tool-hero">
      <p class="estab-tool-eyebrow">Technischer Administrationszugang</p>
      <h1>Kennwortrichtlinie</h1>
      <p>Legen Sie zentral fest, welche Anforderungen beim Erstellen eines
        Funktionskontos und beim Zurücksetzen eines Kennworts gelten.</p>
    </header>

    <aside class="estab-tool-notice" aria-label="Geltungsbereich">
      <strong>Die Änderung gilt nur für künftig gesetzte Kennwörter.</strong>
      <p>Bestehende Kennwörter und Sitzungen bleiben gültig. Eine gegebenenfalls
        aktivierte Selbstregistrierung verwendet dieselbe Richtlinie. Das
        separate technische Administrationskennwort wird hier nicht geändert.</p>
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

    <?php if (is_array($policy)): ?>
      <section class="estab-tool-panel" aria-labelledby="current-policy-title">
        <h2 id="current-policy-title">Aktuell wirksame Richtlinie</h2>
        <p class="estab-password-policy-summary">
          <?= estab_auth_html(estab_password_policy_requirements_text($policy)) ?>
        </p>
        <p class="estab-tool-meta">Version <?= (int) $policy['revision'] ?> ·
          zuletzt geändert von
          <?= estab_auth_html((string) $policy['updated_by']) ?>
          <?php if ((string) $policy['updated_at'] !== ''): ?>
            am <?= estab_auth_html((string) $policy['updated_at']) ?> UTC
          <?php endif; ?>
        </p>
      </section>
    <?php endif; ?>

    <?php if (is_array($policy) && is_array($formPolicy)): ?>
      <section class="estab-tool-panel" aria-labelledby="policy-editor-title">
        <h2 id="policy-editor-title">Richtlinie bearbeiten</h2>
        <p>Leerzeichen bleiben für gut merkbare Passphrasen erlaubt, zählen
          aber nicht als Sonderzeichen. Die Serverprüfung ist verbindlich.</p>
        <form method="post" action="password_policy.php" class="estab-tool-form"
          autocomplete="off" data-estab-dirty-guard>
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="preview">
          <input type="hidden" name="expected_revision"
            value="<?= (int) $policy['revision'] ?>">
          <div class="estab-tool-form-grid">
            <label class="estab-tool-field">
              Mindestlänge
              <input type="number" name="minimum_length"
                min="<?= ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED ?>"
                max="<?= ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED ?>"
                step="1" inputmode="numeric"
                value="<?= (int) $formPolicy['minimum_length'] ?>" required>
              <span class="estab-tool-help">
                <?= ESTAB_PASSWORD_POLICY_MINIMUM_ALLOWED ?> bis
                <?= ESTAB_PASSWORD_POLICY_MAXIMUM_ALLOWED ?> Zeichen
              </span>
            </label>
          </div>
          <fieldset class="estab-password-policy-options">
            <legend>Zusätzliche Anforderungen</legend>
            <?php foreach ([
                'require_uppercase' => 'Mindestens ein Großbuchstabe',
                'require_lowercase' => 'Mindestens ein Kleinbuchstabe',
                'require_digit' => 'Mindestens eine Ziffer',
                'require_symbol' => 'Mindestens ein Sonderzeichen',
            ] as $field => $label): ?>
              <label class="estab-password-policy-option">
                <input type="checkbox" name="<?= estab_auth_html($field) ?>"
                  value="1" <?= !empty($formPolicy[$field]) ? 'checked' : '' ?>>
                <span><?= estab_auth_html($label) ?></span>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <button class="estab-button estab-button-primary" type="submit">
            Änderung prüfen
          </button>
        </form>
      </section>
    <?php endif; ?>

    <?php if (is_array($policy) && is_array($preview)): ?>
      <section class="estab-tool-panel estab-password-policy-confirm"
        aria-labelledby="policy-confirm-title">
        <h2 id="policy-confirm-title">Änderung bestätigen</h2>
        <?php if ($weakened): ?>
          <p class="estab-tool-feedback estab-tool-feedback-warning">
            Die vorgeschlagene Richtlinie ist in mindestens einem Punkt
            schwächer als die aktuell wirksame Richtlinie.
          </p>
        <?php endif; ?>
        <dl class="estab-password-policy-comparison">
          <div>
            <dt>Bisher</dt>
            <dd><?= estab_auth_html(
                estab_password_policy_requirements_text($policy)
            ) ?></dd>
          </div>
          <div>
            <dt>Künftig</dt>
            <dd><?= estab_auth_html(
                estab_password_policy_requirements_text($preview)
            ) ?></dd>
          </div>
        </dl>
        <form method="post" action="password_policy.php"
          class="estab-tool-form" autocomplete="off">
          <?= estab_csrf_field() ?>
          <input type="hidden" name="admin_action" value="apply">
          <input type="hidden" name="expected_revision"
            value="<?= (int) $policy['revision'] ?>">
          <input type="hidden" name="minimum_length"
            value="<?= (int) $preview['minimum_length'] ?>">
          <?php foreach ([
              'require_uppercase', 'require_lowercase',
              'require_digit', 'require_symbol',
          ] as $field): ?>
            <input type="hidden" name="<?= estab_auth_html($field) ?>"
              value="<?= !empty($preview[$field]) ? '1' : '0' ?>">
          <?php endforeach; ?>
          <label class="estab-password-policy-option">
            <input type="checkbox" name="confirm_policy" value="1" required>
            <span>Ich habe die neue Richtlinie geprüft und möchte sie jetzt
              wirksam speichern.</span>
          </label>
          <button class="estab-button estab-button-primary" type="submit">
            Richtlinie wirksam speichern
          </button>
        </form>
      </section>
    <?php endif; ?>

    <footer class="estab-tool-footer">
      <a href="admin.php">Zurück zur Administration</a>
      <a href="users.php">Zur Benutzerverwaltung</a>
    </footer>
  </main>
</body>
</html>
