<?php

/**
 * Safe attachment preview endpoint.
 *
 * The historic implementation accepted an arbitrary server path. The current
 * endpoint accepts only a validated stored basename, resolves it below the
 * active operation's attachment directory and caps all decoded dimensions.
 */

require_once __DIR__ . '/../app/file_access.php';
require_once __DIR__ . '/../app/attachment.php';
require_once __DIR__ . '/../app/read_authorization.php';
require __DIR__ . '/../4fcfg/config.inc.php';
require __DIR__ . '/../4fcfg/dbcfg.inc.php';
require __DIR__ . '/../4fcfg/e_cfg.inc.php';
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function estab_preview_error (int $status, string $message): never {
  http_response_code ($status);
  header ("Content-Type: text/plain; charset=UTF-8");
  header ("Cache-Control: no-store");
  header ("X-Content-Type-Options: nosniff");
  echo $message;
  exit;
}

function estab_preview_dimension (string $name): ?int {
  if (!isset ($_GET[$name]) || $_GET[$name] === "") {
    return null;
  }
  $value = filter_var ($_GET[$name], FILTER_VALIDATE_INT,
                       array ("options" => array ("min_range" => 1, "max_range" => 1600)));
  if ($value === false) {
    estab_preview_error (400, "Ungültige Vorschaugröße.");
  }
  return $value;
}

function estab_preview_placeholder (int $width, int $height): GdImage {
  $image = imagecreatetruecolor ($width, $height);
  $background = imagecolorallocate ($image, 245, 245, 245);
  $foreground = imagecolorallocate ($image, 40, 40, 40);
  imagefilledrectangle ($image, 0, 0, $width, $height, $background);
  imagestring ($image, 3, 10, max (5, (int) (($height - 15) / 2)), "Keine Bildvorschau", $foreground);
  return $image;
}

$readIdentity = session_status() === PHP_SESSION_ACTIVE
  ? estab_read_session_identity ($_SESSION)
  : null;
if (!is_array ($readIdentity)) {
  estab_preview_error (403, "Anmeldung erforderlich.");
}
// Thumbnails can require database scans and GD decoding. Release the PHP
// session lock after taking the immutable identity snapshot so parallel lazy
// previews and normal form actions from the same browser do not serialize.
if (session_status () === PHP_SESSION_ACTIVE) {
  session_write_close ();
}

$requested =
  isset ($_GET["file"]) && is_string ($_GET["file"])
    ? $_GET["file"]
    : "";
$messageWriteRecord = null;
try {
  $requested = estab_file_validate_name ("attachment", $requested);
  $messageWriteRecord = estab_read_attachment_write_record ($_GET);
} catch (InvalidArgumentException) {
  estab_preview_error (400, "Ungültiger Anhangname.");
}

$width = estab_preview_dimension ("width");
$height = estab_preview_dimension ("height");
$zoom = null;
if (isset ($_GET["zoom"]) && $_GET["zoom"] !== "") {
  $zoom = filter_var ($_GET["zoom"], FILTER_VALIDATE_FLOAT);
  if ($zoom === false || $zoom < 0.05 || $zoom > 4.0) {
    estab_preview_error (400, "Ungültiger Zoomfaktor.");
  }
}
if ($width === null && $height === null && $zoom === null) {
  estab_preview_error (400, "Vorschaugröße fehlt.");
}

$connection = null;
$transactionActive = false;
$stream = null;
$attachmentIntegrity = null;
$attachmentAuthorizationVersion = null;
$attachmentWritePermissionContext = null;
$failure = null;
try {
  $connection = estab_attachment_connection ($conf_4f_db);
  if (!$connection->begin_transaction ()) {
    throw new RuntimeException ("Could not start attachment preview transaction");
  }
  $transactionActive = true;
  $attachmentWriteScope = is_int ($messageWriteRecord)
    ? estab_read_attachment_write_scope_for_record (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $readIdentity,
        $messageWriteRecord
      )
    : null;
  if (is_int ($messageWriteRecord)) {
    $attachmentWritePermissionContext = estab_permission_context ();
    if (!is_array ($attachmentWritePermissionContext)) {
      throw new RuntimeException (
        "Attachment write policy snapshot is unavailable"
      );
    }
  }
  $attachment = estab_read_attachment (
    $connection,
    $conf_4f_tbl ["anhang"],
    $conf_4f_tbl ["nachrichten"],
    $requested,
    $readIdentity,
    false,
    $attachmentWriteScope
  );
  if (!is_array ($attachment)) {
    throw new EstabIncidentNotFoundException (
      "Attachment is missing or not readable"
    );
  }
  $attachmentAuthorizationVersion =
    estab_read_attachment_authorization_version ($attachment);
  if (!$connection->commit ()) {
    throw new RuntimeException (
      "Could not commit initial attachment preview authorization"
    );
  }
  $transactionActive = false;

  // Copy and hash the file while no database row is locked. The private
  // stream is re-bound to a fresh object authorization below before output.
  try {
    $attachmentIntegrity = estab_attachment_integrity_open_snapshot (
      $attachment,
      (string) $conf_4f["ablage_dir"],
      $requested
    );
    $stream = $attachmentIntegrity ["stream"];
  } catch (EstabAttachmentIntegrityException $exception) {
    throw $exception;
  } catch (RuntimeException $exception) {
    throw new EstabIncidentNotFoundException (
      "Authorized attachment preview is unavailable",
      previous: $exception
    );
  }

  if (!$connection->begin_transaction ()) {
    throw new RuntimeException (
      "Could not start final attachment preview authorization"
    );
  }
  $transactionActive = true;
  $currentAttachmentWriteScope = is_int ($messageWriteRecord)
    ? estab_read_attachment_write_scope_for_record (
        $connection,
        $conf_4f_tbl ["nachrichten"],
        $readIdentity,
        $messageWriteRecord,
        $attachmentWritePermissionContext,
        true
      )
    : null;
  $currentAttachment = estab_read_attachment (
    $connection,
    $conf_4f_tbl ["anhang"],
    $conf_4f_tbl ["nachrichten"],
    $requested,
    $readIdentity,
    true,
    $currentAttachmentWriteScope
  );
  if (
    !is_array ($currentAttachment)
    || !is_string ($attachmentAuthorizationVersion)
    || !hash_equals (
      $attachmentAuthorizationVersion,
      estab_read_attachment_authorization_version ($currentAttachment)
    )
  ) {
    throw new EstabIncidentNotFoundException (
      "Attachment authorization changed during preview snapshot"
    );
  }
  if (!$connection->commit ()) {
    throw new RuntimeException (
      "Could not commit final attachment preview authorization"
    );
  }
  $transactionActive = false;
} catch (EstabNoActiveIncidentException|EstabIncidentConflictException) {
  $failure = array (409, "Kein Einsatz aktiv.");
} catch (EstabIncidentNotFoundException) {
  $failure = array (404, "Anhang nicht gefunden.");
} catch (EstabReadPermissionException) {
  $failure = array (403, "Aktion nicht erlaubt.");
} catch (EstabAttachmentIntegrityException $exception) {
  error_log (
    "eStab attachment preview integrity failed: ".$exception->getMessage ()
  );
  $failure = array (
    409,
    "Die Integrität des Anhangs konnte nicht bestätigt werden."
  );
} catch (Throwable $exception) {
  error_log ("eStab attachment preview scope failed: ".$exception->getMessage ());
  $failure = array (503, "Der Anhang kann derzeit nicht geprüft werden.");
} finally {
  if ($connection instanceof mysqli) {
    if ($transactionActive) {
      $connection->rollback ();
    }
    estab_attachment_close ($connection);
  }
  if ($failure !== null && is_resource ($stream)) {
    fclose ($stream);
    $stream = null;
  }
}
if (is_array ($failure)) {
  estab_preview_error ((int) $failure [0], (string) $failure [1]);
}
if (!is_resource ($stream)) {
  estab_preview_error (503, "Der Anhang konnte nicht geöffnet werden.");
}
$previewByteLimit = 24 * 1024 * 1024;
$snapshotSize = is_array ($attachmentIntegrity)
  ? (int) ($attachmentIntegrity ["content_size"] ?? -1)
  : -1;
$imageBytes = $snapshotSize >= 0 && $snapshotSize <= $previewByteLimit
  ? stream_get_contents ($stream)
  : null;
fclose ($stream);
if ($snapshotSize <= $previewByteLimit && !is_string ($imageBytes)) {
  estab_preview_error (503, "Der Anhang konnte nicht gelesen werden.");
}

$imageInfo = is_string ($imageBytes)
  ? @getimagesizefromstring ($imageBytes)
  : false;
$source = false;
if ($imageInfo !== false && $imageInfo[0] > 0 && $imageInfo[1] > 0
    // Keep worst-case GD memory bounded on small NAS deployments. Large
    // originals remain downloadable; only their automatic thumbnail falls
    // back to the lightweight placeholder.
    && ($imageInfo[0] * $imageInfo[1]) <= 16000000
    && in_array (
      $imageInfo[2],
      array (IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP),
      true
    )) {
  $source = @imagecreatefromstring ((string) $imageBytes);
}
unset ($imageBytes);

if (!$source) {
  $targetWidth = $width ?? 250;
  $targetHeight = $height ?? 60;
  $targetWidth = min ($targetWidth, 800);
  $targetHeight = min ($targetHeight, 200);
  $target = estab_preview_placeholder ($targetWidth, $targetHeight);
} else {
  $sourceWidth = imagesx ($source);
  $sourceHeight = imagesy ($source);
  if ($zoom !== null) {
    $targetWidth = max (1, min (1600, (int) round ($sourceWidth * $zoom)));
    $targetHeight = max (1, min (1600, (int) round ($sourceHeight * $zoom)));
  } elseif ($width !== null && $height !== null) {
    $scale = min ($width / $sourceWidth, $height / $sourceHeight);
    $targetWidth = max (1, (int) round ($sourceWidth * $scale));
    $targetHeight = max (1, (int) round ($sourceHeight * $scale));
  } elseif ($width !== null) {
    $targetWidth = $width;
    $targetHeight = max (1, (int) round ($sourceHeight * ($width / $sourceWidth)));
  } else {
    $targetHeight = $height;
    $targetWidth = max (1, (int) round ($sourceWidth * ($height / $sourceHeight)));
  }
  if ($targetWidth > 1600 || $targetHeight > 1600) {
    $source = null;
    estab_preview_error (400, "Vorschaugröße überschreitet das Limit.");
  }

  $target = imagecreatetruecolor ($targetWidth, $targetHeight);
  imagealphablending ($target, false);
  imagesavealpha ($target, true);
  $transparent = imagecolorallocatealpha ($target, 255, 255, 255, 127);
  imagefilledrectangle ($target, 0, 0, $targetWidth, $targetHeight, $transparent);
  imagecopyresampled ($target, $source, 0, 0, 0, 0,
                      $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
  $source = null;
}

header ("Content-Type: image/png");
header ("Content-Disposition: inline; filename=preview.png");
header ("Cache-Control: private, no-store");
header ("X-Content-Type-Options: nosniff");
if (is_array ($attachmentIntegrity)) {
  header (
    "X-eStab-Attachment-Integrity: "
      .(string) $attachmentIntegrity ["state"]
  );
  if (
    $attachmentIntegrity ["state"] === "verified"
    && is_string ($attachmentIntegrity ["sha256"])
  ) {
    header (
      "X-eStab-Attachment-SHA256: "
        .$attachmentIntegrity ["sha256"]
    );
  }
}
imagepng ($target);
$target = null;
