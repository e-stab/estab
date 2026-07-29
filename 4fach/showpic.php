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

if (session_status() !== PHP_SESSION_ACTIVE
    || !estab_auth_session_is_authenticated ($_SESSION)) {
  estab_preview_error (403, "Anmeldung erforderlich.");
}

$requested =
  isset ($_GET["file"]) && is_string ($_GET["file"])
    ? $_GET["file"]
    : "";
try {
  $requested = estab_file_validate_name ("attachment", $requested);
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

$storedName = pathinfo ($requested, PATHINFO_FILENAME);
$extension = strtolower (pathinfo ($requested, PATHINFO_EXTENSION));
$connection = null;
$transactionActive = false;
$stream = null;
$failure = null;
try {
  $connection = estab_attachment_connection ($conf_4f_db);
  if (!$connection->begin_transaction ()) {
    throw new RuntimeException ("Could not start attachment preview transaction");
  }
  $transactionActive = true;
  $attachment = estab_attachment_find (
    $connection,
    $conf_4f_tbl ["anhang"],
    $storedName,
    true
  );
  if (
    !is_array ($attachment)
    || !hash_equals (
      strtolower ((string) ($attachment ["fileext"] ?? "")),
      $extension
    )
  ) {
    throw new EstabIncidentNotFoundException (
      "Attachment does not belong to the active incident"
    );
  }
  try {
    $stream = estab_file_open (
      (string) $conf_4f["ablage_dir"],
      "attachment",
      $requested
    );
  } catch (RuntimeException $exception) {
    throw new EstabIncidentNotFoundException (
      "Authorized attachment preview is unavailable",
      previous: $exception
    );
  }
  if (!$connection->commit ()) {
    throw new RuntimeException ("Could not commit attachment preview transaction");
  }
  $transactionActive = false;
} catch (EstabNoActiveIncidentException) {
  $failure = array (409, "Kein Einsatz aktiv.");
} catch (EstabIncidentNotFoundException) {
  $failure = array (404, "Anhang nicht gefunden.");
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
$imageBytes = stream_get_contents ($stream);
fclose ($stream);
if (!is_string ($imageBytes)) {
  estab_preview_error (503, "Der Anhang konnte nicht gelesen werden.");
}

$imageInfo = @getimagesizefromstring ($imageBytes);
$source = false;
if ($imageInfo !== false && $imageInfo[0] > 0 && $imageInfo[1] > 0
    && ($imageInfo[0] * $imageInfo[1]) <= 40000000
    && in_array (
      $imageInfo[2],
      array (IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP),
      true
    )) {
  $source = @imagecreatefromstring ($imageBytes);
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
imagepng ($target);
$target = null;
