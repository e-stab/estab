<?php

/**
 * Shared HTTP-upload boundary for message attachments.
 *
 * Both the integrated message form and the legacy attachment archive use this
 * service. Filename reservation, MIME validation, integrity capture,
 * active-incident finalisation, metadata and audit therefore remain one
 * implementation.
 */

require_once __DIR__ . '/attachment.php';
require_once __DIR__ . '/../4fach/upload_class.php';

final class EstabAttachmentUploadUserException extends RuntimeException
{
}

function estab_attachment_upload_max_bytes(): int
{
    $limit = 20971520;
    $configured = getenv('ESTAB_UPLOAD_MAX_BYTES');
    if (
        $configured !== false
        && is_string($configured)
        && ctype_digit($configured)
    ) {
        $limit = (int) $configured;
    }
    return max(1, min($limit, 52428800));
}

function estab_attachment_upload_limit_label(?int $bytes = null): string
{
    $bytes = $bytes ?? estab_attachment_upload_max_bytes();
    if ($bytes < 1024) {
        return number_format($bytes, 0, ',', '.') . ' Byte';
    }
    if ($bytes < 1048576) {
        $kibibytes = $bytes / 1024;
        return number_format(
            $kibibytes,
            $bytes % 1024 === 0 ? 0 : 1,
            ',',
            '.'
        ) . ' KiB';
    }
    $mebibytes = $bytes / 1048576;
    return number_format(
        $mebibytes,
        $bytes % 1048576 === 0 ? 0 : 1,
        ',',
        '.'
    ) . ' MiB';
}

function estab_attachment_upload_accept(): string
{
    return implode(',', array_map(
        static fn (string $extension): string => '.' . $extension,
        estab_attachment_allowed_extensions()
    ));
}

/** Convert PHP's K/M/G shorthand into bytes; zero means no transport limit. */
function estab_attachment_upload_ini_bytes(mixed $value): ?int
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if (preg_match('/\A([0-9]+)\s*([KMG]?)\z/Di', $value, $parts) !== 1) {
        return null;
    }
    $bytes = (int) $parts[1];
    if ($bytes === 0) {
        return null;
    }
    $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3];
    $power = $powers[strtoupper($parts[2])] ?? null;
    if (!is_int($power)) {
        return null;
    }
    for ($index = 0; $index < $power; $index++) {
        if ($bytes > intdiv(PHP_INT_MAX, 1024)) {
            return PHP_INT_MAX;
        }
        $bytes *= 1024;
    }
    return $bytes;
}

/** Detect the PHP failure mode that discards the complete multipart body. */
function estab_attachment_upload_post_body_exceeded(
    array $server,
    array $post,
    array $files,
    mixed $postMaxSize = null
): bool {
    if (
        strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        || $post !== []
        || $files !== []
    ) {
        return false;
    }
    $contentType = strtolower((string) ($server['CONTENT_TYPE'] ?? ''));
    $contentLength = $server['CONTENT_LENGTH'] ?? null;
    if (
        !str_starts_with($contentType, 'multipart/form-data')
        || !is_string($contentLength)
        || !ctype_digit($contentLength)
    ) {
        return false;
    }
    $limit = estab_attachment_upload_ini_bytes(
        $postMaxSize ?? ini_get('post_max_size')
    );
    return is_int($limit) && (int) $contentLength > $limit;
}

/** Return a stable, user-facing upload failure without exposing internals. */
function estab_attachment_upload_user_failure(
    file_upload $uploader,
    string $fallback = 'Der Anhang konnte nicht sicher gespeichert werden.'
): EstabAttachmentUploadUserException {
    $message = $uploader->failure_code === null
        ? $fallback
        : $uploader->user_error_message();
    return new EstabAttachmentUploadUserException($message);
}

/**
 * Validate, persist and audit one PHP browser upload.
 *
 * A pre-reserved name is accepted only for the legacy two-step archive form.
 * The integrated message form omits it, so the server reserves and finalises
 * the name in the same request. Browser MIME, size, user code, timestamp and
 * destination filename are never authority inputs.
 *
 * @return array<string,mixed> validated attachment metadata plus `reference`
 */
function estab_attachment_upload_browser_file(
    array $upload,
    mixed $comment,
    array $identity,
    mixed $expectedIncidentId,
    array $databaseConfig,
    string $attachmentTable,
    string $protocolTable,
    string $prefix,
    string $storageRoot,
    string $sessionId,
    ?array $originContext,
    array $server = [],
    ?string $preReservedName = null,
    ?string $capturedAt = null
): array {
    $expectedIncidentId = estab_incident_positive_id($expectedIncidentId);
    $identity = estab_attachment_origin_identity($identity);
    if ($originContext !== null) {
        $originContext = estab_attachment_origin_context_validate(
            $originContext,
            $identity,
            $expectedIncidentId
        );
    }
    $sessionId = estab_attachment_validate_session_id($sessionId);
    $prefix = estab_attachment_validate_prefix($prefix);
    $storageRoot = rtrim($storageRoot, "/\\");
    if ($storageRoot === '') {
        throw new InvalidArgumentException('Invalid attachment storage root');
    }

    if (!is_string($comment)) {
        throw new EstabAttachmentUploadUserException(
            'Die Beschreibung des Anhangs ist ungültig.'
        );
    }
    $comment = trim($comment);
    if (!estab_attachment_text_is_valid($comment, 255, true)) {
        throw new EstabAttachmentUploadUserException(
            'Die Beschreibung darf höchstens 255 Zeichen enthalten.'
        );
    }

    if (
        !isset($upload['tmp_name'], $upload['name'], $upload['error'])
        || !is_string($upload['tmp_name'])
        || !is_string($upload['name'])
        || !is_int($upload['error'])
    ) {
        throw new EstabAttachmentUploadUserException(
            'Die Upload-Daten sind ungültig. Bitte wählen Sie die Datei erneut aus.'
        );
    }
    $uploader = new file_upload();
    $uploader->max_file_size = estab_attachment_upload_max_bytes();
    $uploader->http_error = $upload['error'];
    if ($uploader->http_error !== UPLOAD_ERR_OK) {
        $uploader->failure_code = $uploader->http_error;
        throw estab_attachment_upload_user_failure($uploader);
    }

    $originalName = basename(str_replace('\\', '/', trim($upload['name'])));
    if (!estab_attachment_text_is_valid($originalName, 255, false)) {
        throw new EstabAttachmentUploadUserException(
            'Der ursprüngliche Dateiname ist ungültig oder zu lang.'
        );
    }
    $requestedExtension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );
    if (!estab_attachment_extension_is_allowed($requestedExtension)) {
        throw new EstabAttachmentUploadUserException(
            'Diese Dateiendung wird nicht unterstützt.'
        );
    }

    $uploader->upload_dir = $storageRoot . DIRECTORY_SEPARATOR;
    $uploader->extensions = array_map(
        static fn (string $extension): string => '.' . $extension,
        estab_attachment_allowed_extensions()
    );
    $uploader->max_length_filename = 100;
    $uploader->rename_file = true;
    $uploader->replace = false;
    $uploader->do_filename_check = false;
    $uploader->the_temp_file = $upload['tmp_name'];
    $uploader->the_file = $originalName;

    if ($capturedAt === null) {
        $capturedAt = date('Y-m-d H:i:s');
    }
    if (!estab_attachment_validate_sql_datetime($capturedAt)) {
        throw new EstabAttachmentUploadUserException(
            'Der Zeitstempel des Anhangs ist ungültig.'
        );
    }

    $owner = estab_attachment_reservation_owner_id(
        $sessionId,
        $originContext
    );
    $reservation = $preReservedName === null
        ? null
        : estab_attachment_validate_reservation_name(
            $preReservedName,
            $prefix
        );
    $connection = estab_attachment_connection($databaseConfig);
    $fullPath = null;
    $finalized = false;
    $reservationIncidentId = null;
    try {
        if ($reservation === null) {
            $reservation = estab_attachment_reserve(
                $connection,
                $attachmentTable,
                $prefix,
                $owner,
                $identity,
                4,
                8,
                null,
                $expectedIncidentId
            );
        }
        $reservationIncidentId =
            estab_attachment_owned_reservation_incident_id(
                $connection,
                $attachmentTable,
                $owner,
                $reservation
            );
        if (!is_int($reservationIncidentId)) {
            throw new EstabAttachmentUploadUserException(
                'Die Upload-Reservierung ist abgelaufen. Bitte versuchen Sie es erneut.'
            );
        }
        if ($reservationIncidentId !== $expectedIncidentId) {
            throw new EstabIncidentConflictException(
                'Der aktive Einsatz hat sich vor dem Upload geändert.'
            );
        }
        $stagedExtension = estab_attachment_prepare_staged_extension(
            $connection,
            $attachmentTable,
            $owner,
            $reservation,
            $reservationIncidentId,
            $requestedExtension,
            $identity
        );
        $fullPath = $storageRoot . DIRECTORY_SEPARATOR
            . $reservation . '.' . $stagedExtension;
        if (!hash_equals($requestedExtension, $stagedExtension)) {
            throw new EstabAttachmentUploadUserException(
                'Ein zuvor unterbrochener Upload wird sicher bereinigt. '
                . 'Bitte wählen Sie die Datei anschließend erneut aus.'
            );
        }
        // Move and measure the already validated upload before acquiring the
        // operational incident transaction. The reserved filename is not
        // readable while its row remains unfinished, but slow NAS I/O and
        // hashing no longer serialize unrelated message/incident writes.
        if (!$uploader->upload($reservation)) {
            throw estab_attachment_upload_user_failure($uploader);
        }
        $uploadedPath = $uploader->upload_dir . $uploader->file_copy;
        if (!hash_equals($fullPath, $uploadedPath)) {
            throw new RuntimeException(
                'The staged attachment path changed unexpectedly'
            );
        }
        $md5 = md5_file($fullPath);
        if (!is_string($md5)) {
            throw new RuntimeException(
                'The legacy attachment digest could not be measured'
            );
        }
        $integrity = estab_attachment_integrity_measure_file($fullPath);
        $preparedMetadata = [
            'filename' => basename($fullPath),
            'org_filename' => $originalName,
            'comment' => $comment,
            'time' => $capturedAt,
            'md5hash' => $md5,
            'sha256' => $integrity['sha256'],
            'size' => $integrity['size'],
        ];
        $stored = estab_attachment_store_upload(
            $connection,
            $attachmentTable,
            $protocolTable,
            $reservation,
            $owner,
            (string) $identity['kuerzel'],
            $identity,
            'Anhangdaten speichern',
            static fn (): array => $preparedMetadata,
            static function (array $metadata) use (
                $identity,
                $sessionId,
                $server
            ): string {
                return (string) $identity['benutzer'] . ';'
                    . (string) $identity['kuerzel'] . ';'
                    . (string) $identity['funktion'] . ';'
                    . (string) $identity['rolle'] . ';'
                    . $sessionId . ';'
                    . estab_auth_remote_ip($server) . ';'
                    . $metadata['filename'] . '.' . $metadata['fileext'] . ';'
                    . $metadata['org_filename'] . ';'
                    . $metadata['date'] . ';sha256=' . $metadata['sha256'] . ';'
                    . 'bytes=' . (string) $metadata['size'];
            },
            $expectedIncidentId
        );
        if (!is_array($stored)) {
            throw new EstabAttachmentUploadUserException(
                'Die Upload-Reservierung ist abgelaufen. Bitte versuchen Sie es erneut.'
            );
        }
        $stored['reference'] = $stored['filename'] . '.' . $stored['fileext'];
        $finalized = true;
        return $stored;
    } finally {
        estab_attachment_close($connection);
        $releaseReservation = false;
        $cleanupState = null;
        $cleanupIncidentId = is_int($reservationIncidentId)
            ? $reservationIncidentId
            : $expectedIncidentId;
        if (
            !$finalized
            && is_string($reservation)
            && $reservation !== ''
        ) {
            $stateConnection = null;
            try {
                $stateConnection = estab_attachment_connection($databaseConfig);
                $cleanupState = estab_attachment_reservation_cleanup_state(
                    $stateConnection,
                    $attachmentTable,
                    $owner,
                    $reservation,
                    $cleanupIncidentId
                );
            } catch (Throwable $stateException) {
                error_log(
                    'eStab attachment cleanup state is unavailable: '
                    . $stateException->getMessage()
                );
            } finally {
                if ($stateConnection instanceof mysqli) {
                    estab_attachment_close($stateConnection);
                }
            }
        }
        if (($cleanupState['state'] ?? null) === 'finalized') {
            // A connection failure can make COMMIT's client-side result
            // ambiguous. The completed row is authoritative: never remove
            // bytes belonging to its immutable integrity record.
            error_log(
                'eStab attachment upload recovered an already finalized row'
            );
        } elseif (($cleanupState['state'] ?? null) === 'owned-unfinished') {
            $cleanupExtension = $cleanupState['extension'] ?? null;
            $cleanupPath = is_string($cleanupExtension)
                ? $storageRoot . DIRECTORY_SEPARATOR
                    . $reservation . '.' . $cleanupExtension
                : null;
            if ($cleanupPath === null) {
                // No suffix was ever staged, therefore no destination path
                // could have been handed to the uploader.
                $releaseReservation = true;
            } else {
                $rootPrefix = $storageRoot . DIRECTORY_SEPARATOR;
                $expectedBasename = $reservation . '.' . $cleanupExtension;
                if (
                    str_starts_with($cleanupPath, $rootPrefix)
                    && hash_equals($expectedBasename, basename($cleanupPath))
                ) {
                    if (is_file($cleanupPath)) {
                        @unlink($cleanupPath);
                    }
                    $releaseReservation = !is_file($cleanupPath);
                    if (!$releaseReservation) {
                        error_log(
                            'eStab staged attachment cleanup failed; '
                            . 'reservation retained: ' . basename($cleanupPath)
                        );
                    }
                } else {
                    error_log(
                        'eStab staged attachment cleanup rejected an unexpected path'
                    );
                }
            }
        } elseif (
            is_array($cleanupState)
            && ($cleanupState['state'] ?? null) !== 'missing'
        ) {
            error_log(
                'eStab attachment cleanup retained an unsafe reservation state'
            );
        }
        if (
            !$finalized
            && $releaseReservation
            && is_string($reservation)
            && $reservation !== ''
        ) {
            $cleanup = null;
            try {
                $cleanup = estab_attachment_connection($databaseConfig);
                estab_attachment_release_for_incident(
                    $cleanup,
                    $attachmentTable,
                    $owner,
                    $reservation,
                    $cleanupIncidentId
                );
            } catch (Throwable $cleanupException) {
                error_log(
                    'eStab attachment reservation cleanup failed: '
                    . $cleanupException->getMessage()
                );
            } finally {
                if ($cleanup instanceof mysqli) {
                    estab_attachment_close($cleanup);
                }
            }
        }
    }
}
