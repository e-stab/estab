<?php

/**
 * Screen representation of the official THW Nachrichtenvordruck.
 *
 * The printed grid is deliberately kept separate from eStab-specific workflow
 * controls. This preserves the official visual hierarchy while the legacy
 * controller continues to receive the field names it already authorises.
 */
trait EstabOfficialMessageFormView
{
    /** Carry one server-selected Stab/FB workspace through form round-trips. */
    function official_message_acting_function(): ?string
    {
        $identity = $GLOBALS['workflowSelectedIdentity'] ?? null;
        if (function_exists('estab_workflow_staff_acting_function')) {
            return estab_workflow_staff_acting_function($identity);
        }
        if (
            !is_array($identity)
            || !is_string($identity['funktion'] ?? null)
            || !is_string($identity['rolle'] ?? null)
            || !estab_auth_has_staff_message_workspace(
                $identity['funktion'],
                $identity['rolle']
            )
        ) {
            return null;
        }
        return $identity['funktion'];
    }

    /** @return list<string> */
    function official_message_attachment_references(): array
    {
        $stored = $this->formdata['12_anhang'] ?? '';
        if (!is_string($stored) || $stored === '') {
            return [];
        }
        $references = [];
        foreach (explode(';', $stored) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || isset($references[$candidate])) {
                continue;
            }
            try {
                $reference = estab_file_validate_name(
                    'attachment',
                    $candidate
                );
            } catch (InvalidArgumentException) {
                continue;
            }
            $references[$reference] = $reference;
        }
        return array_values($references);
    }

    function official_message_attachments_editable(): bool
    {
        return in_array(
            (string) $this->task,
            estab_attachment_origin_tasks(),
            true
        );
    }

    function official_message_attachment_size(mixed $bytes): string
    {
        if (!is_int($bytes) && !(is_string($bytes) && ctype_digit($bytes))) {
            return '';
        }
        $size = (int) $bytes;
        if ($size < 0) {
            return '';
        }
        if ($size < 1024) {
            return number_format($size, 0, ',', '.') . ' Byte';
        }
        if ($size < 1048576) {
            return number_format($size / 1024, 1, ',', '.') . ' KiB';
        }
        return number_format($size / 1048576, 1, ',', '.') . ' MiB';
    }

    function official_message_attachment_date(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d H:i:s') === $value
            ? $date->format('d.m.Y H:i') . ' Uhr'
            : '';
    }

    /**
     * Render application controls outside the immutable official paper grid.
     * Attachment metadata has already crossed the object-level read boundary
     * in the controller; unresolvable rows deliberately fall back to the
     * internal reference without disclosing database values.
     */
    function official_message_attachments(): void
    {
        include __DIR__ . '/../4fcfg/config.inc.php';
        $references = $this->official_message_attachment_references();
        $editable = $this->official_message_attachments_editable();
        if (!$editable && $references === []) {
            return;
        }
        $count = count($references);
        $allowedExtensions = estab_attachment_allowed_extensions();
        $accept = estab_attachment_upload_accept();
        $formatNames = strtoupper(implode(', ', $allowedExtensions));
        $uploadLimit = estab_attachment_upload_limit_label();
        $previewEndpoint = dirname((string) $conf_4f['download_uri'])
            . '/showpic.php';
        $emailEndpoint = dirname((string) $conf_4f['download_uri'])
            . '/email.php';
        $attachmentError = $this->formdata['estab_attachment_error'] ?? '';
        $attachmentNotice = $this->formdata['estab_attachment_notice'] ?? '';
        $messageWriteRecord = null;
        if ($this->task === 'Stab_korrigieren') {
            try {
                $messageWriteRecord = estab_message_positive_id(
                    $this->formdata['00_lfd'] ?? null
                );
            } catch (InvalidArgumentException) {
                $messageWriteRecord = null;
            }
        }
        $hasAttachmentFeedback =
            (is_string($attachmentError) && $attachmentError !== '')
            || (is_string($attachmentNotice) && $attachmentNotice !== '');
        $directActionToken = null;
        if ($editable) {
            try {
                if (!isset($_SESSION) || !is_array($_SESSION)) {
                    throw new EstabAttachmentContextException(
                        'Der direkte Upload besitzt keine gültige Sitzung.'
                    );
                }
                $identity = $GLOBALS['workflowSelectedIdentity'] ?? null;
                if (!is_array($identity)) {
                    $identity = estab_auth_session_identity($_SESSION);
                }
                $incidentId = $GLOBALS['workflowIncidentId'] ?? null;
                if (!is_array($identity)) {
                    throw new EstabAttachmentContextException(
                        'Der direkte Upload besitzt keine gültige Sitzung.'
                    );
                }
                $directActionToken = estab_attachment_direct_action_issue(
                    $_SESSION,
                    $identity,
                    $incidentId,
                    (string) $this->task,
                    $this->formdata['00_lfd'] ?? null
                );
            } catch (Throwable $exception) {
                error_log(
                    'eStab direct attachment action token unavailable: '
                        . $exception->getMessage()
                );
            }
        }

        echo '<section id="nachrichtenanlagen" '
            . 'class="estab-message-attachments" '
            . 'aria-labelledby="nachrichtenanlagen-title" '
            . ($hasAttachmentFeedback
                ? 'tabindex="-1" data-estab-attachment-feedback '
                : '')
            . 'data-estab-message-attachments data-estab-attachment-count="'
            . $count . '">'
            . '<header class="estab-message-attachments-header"><div>'
            . '<span class="estab-section-kicker">Nachrichtenzubehör</span>'
            . '<h2 id="nachrichtenanlagen-title">Anlagen (' . $count . ')</h2>'
            . '<p>Anlagen gehören unmittelbar zu diesem Nachrichtenvordruck. '
            . 'Sie können sie hier prüfen, herunterladen und – soweit möglich – '
            . 'direkt im Browser ansehen.</p></div></header>';

        if (is_string($attachmentError) && $attachmentError !== '') {
            echo '<div class="estab-alert estab-alert--danger" role="alert">'
                . estab_message_html($attachmentError) . '</div>';
        }
        if (is_string($attachmentNotice) && $attachmentNotice !== '') {
            echo '<div class="estab-alert estab-alert--success" role="status">'
                . estab_message_html($attachmentNotice) . '</div>';
        }

        if ($editable && is_string($directActionToken)) {
            $comment = $this->formdata['estab_attachment_comment'] ?? '';
            echo '<fieldset class="estab-message-attachment-upload">'
                . '<legend>Neue Anlage hinzufügen</legend>'
                . '<input type="hidden" '
                . 'name="message_attachment_request_token" value="'
                . estab_message_html($directActionToken) . '">'
                . '<div class="estab-message-attachment-upload-grid">'
                . '<label class="estab-message-attachment-file" '
                . 'for="message-attachment-upload">'
                . '<strong>Datei auswählen</strong>'
                . '<input id="message-attachment-upload" type="file" '
                . 'name="message_attachment_upload" accept="'
                . estab_message_html($accept) . '" '
                . 'data-estab-max-bytes="'
                . estab_message_html((string) estab_attachment_upload_max_bytes())
                . '" aria-describedby="message-attachment-upload-help '
                . 'message-attachment-upload-error"></label>'
                . '<label for="message-attachment-comment">'
                . '<strong>Beschreibung <span>(optional)</span></strong>'
                . '<input id="message-attachment-comment" type="text" '
                . 'name="message_attachment_comment" maxlength="255" value="'
                . estab_message_html(is_string($comment) ? $comment : '')
                . '" placeholder="z. B. Lagekarte, Stand 20:15 Uhr"></label>'
                . '</div><small id="message-attachment-upload-help">'
                . 'Erlaubte Formate: ' . estab_message_html($formatNames)
                . '. Maximale Dateigröße: '
                . estab_message_html($uploadLimit) . '. Für E-Mail-Dateien '
                . 'im .eml-Format gilt zusätzlich ein festes Sicherheitslimit '
                . 'von 20 MiB.</small>'
                . '<div class="estab-message-attachment-upload-actions">'
                . '<button type="submit" '
                . 'name="message_attachment_upload_x" value="1" '
                . 'class="estab-button estab-button-primary" formnovalidate>'
                . 'Datei hochladen</button>'
                . '<button type="submit" name="anhang_plus_x" value="1" '
                . 'class="estab-button estab-button-secondary" formnovalidate>'
                . 'Bereits hochgeladene Anlage auswählen</button></div>'
                . '<p id="message-attachment-upload-error" '
                . 'class="estab-attachment-client-error" role="alert" '
                . 'hidden></p>'
                . '</fieldset>';
        } elseif ($editable) {
            echo '<div class="estab-alert estab-alert--danger" role="alert">'
                . 'Der direkte Upload kann derzeit nicht sicher vorbereitet '
                . 'werden. Laden Sie den Nachrichtenvordruck neu oder wählen '
                . 'Sie eine bereits archivierte Anlage aus.</div>'
                . '<p><button type="submit" name="anhang_plus_x" value="1" '
                . 'class="estab-button estab-button-secondary" formnovalidate>'
                . 'Bereits hochgeladene Anlage auswählen</button></p>';
        }

        $hasEmbeddedPdf = false;
        $hasEmbeddedEmail = false;
        if ($references === []) {
            echo '<p class="estab-message-attachments-empty">'
                . '<strong>Noch keine Anlage hinzugefügt.</strong> '
                . 'Nach dem Hochladen erscheint die Datei sofort hier am '
                . 'Nachrichtenvordruck.</p>';
        } else {
            echo '<div class="estab-message-attachment-cards">';
            foreach ($references as $reference) {
                $metadata = is_array($this->attachmentPreviews[$reference] ?? null)
                    ? $this->attachmentPreviews[$reference]
                    : [];
                $metadataAvailable = $metadata !== [];
                $originalName = is_string($metadata['org_filename'] ?? null)
                    && trim($metadata['org_filename']) !== ''
                    ? trim($metadata['org_filename'])
                    : $reference;
                $comment = is_string($metadata['comment'] ?? null)
                    ? trim($metadata['comment'])
                    : '';
                $size = $this->official_message_attachment_size(
                    $metadata['ingest_size'] ?? null
                );
                $date = $this->official_message_attachment_date(
                    $metadata['date'] ?? null
                );
                try {
                    $downloadUrl = estab_file_download_url(
                        (string) $conf_4f['download_uri'],
                        'attachment',
                        $reference
                    );
                } catch (InvalidArgumentException) {
                    continue;
                }
                if (is_int($messageWriteRecord)) {
                    $downloadUrl .= '&' . http_build_query(
                        ['message_write_record' => $messageWriteRecord],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                }
                $inlineUrl = $downloadUrl . '&view=inline';
                $extension = strtolower(
                    pathinfo($reference, PATHINFO_EXTENSION)
                );
                $isImage = in_array(
                    $extension,
                    ['jpg', 'jpeg', 'png', 'gif', 'bmp'],
                    true
                );
                $isEmail = $extension === 'eml';
                $previewParameters = ['file' => $reference, 'width' => 640];
                $emailParameters = ['file' => $reference];
                if (is_int($messageWriteRecord)) {
                    $previewParameters['message_write_record'] =
                        $messageWriteRecord;
                    $emailParameters['message_write_record'] =
                        $messageWriteRecord;
                }
                $previewUrl = $previewEndpoint . '?'
                    . http_build_query(
                        $previewParameters,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
                $emailUrl = $emailEndpoint . '?'
                    . http_build_query(
                        $emailParameters,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );

                echo '<article class="estab-message-attachment-card" '
                    . (!$metadataAvailable
                        ? 'data-estab-attachment-unavailable '
                        : '')
                    . ($isEmail ? 'data-estab-email-attachment ' : '')
                    . 'data-estab-message-attachment="'
                    . estab_message_html($reference) . '">';
                if ($metadataAvailable && $isImage) {
                    echo '<a class="estab-message-attachment-preview" href="'
                        . estab_message_html($inlineUrl)
                        . '" target="_blank" rel="noopener" '
                        . 'aria-label="' . estab_message_html(
                            $originalName . ' in neuem Browser-Tab ansehen'
                        ) . '"><img loading="lazy" decoding="async" '
                        . 'fetchpriority="low" src="'
                        . estab_message_html($previewUrl) . '" alt="Vorschau: '
                        . estab_message_html($originalName) . '"></a>';
                } else {
                    echo '<div class="estab-message-attachment-filetype" '
                        . 'aria-hidden="true"><span>'
                        . estab_message_html(
                            $isEmail
                                ? 'E-MAIL'
                                : strtoupper($extension ?: 'Datei')
                        )
                        . '</span></div>';
                }
                echo '<div class="estab-message-attachment-details">'
                    . '<h3>' . estab_message_html($originalName) . '</h3>';
                if (!$metadataAvailable) {
                    echo '<p class="estab-message-attachment-unavailable" '
                        . 'role="status"><strong>Anlage derzeit nicht '
                        . 'verfügbar.</strong> Berechtigung und Integrität '
                        . 'konnten für diese Ansicht nicht bestätigt werden. '
                        . 'Laden Sie den Vordruck neu.</p>';
                }
                if ($comment !== '') {
                    echo '<p>' . estab_message_html($comment) . '</p>';
                }
                $facts = array_values(array_filter([$date, $size]));
                if ($facts !== []) {
                    echo '<p class="estab-message-attachment-meta">'
                        . estab_message_html(implode(' · ', $facts)) . '</p>';
                }
                echo '<p class="estab-message-attachment-reference">'
                    . 'Interne Anlagen-ID: '
                    . estab_message_html($reference) . '</p>';
                if ($metadataAvailable || $editable) {
                    echo '<div class="estab-message-attachment-actions">';
                }
                if ($metadataAvailable) {
                    echo '<a class="estab-button estab-button-secondary" href="'
                        . estab_message_html($downloadUrl)
                        . ($isEmail
                            ? '" download="' . estab_message_html($originalName)
                            : '')
                        . '" aria-label="' . estab_message_html(
                            $isEmail
                                ? $originalName
                                    . ' als unveränderte Originaldatei herunterladen'
                                : $originalName . ' herunterladen'
                        ) . '">' . ($isEmail
                            ? 'Originaldatei herunterladen'
                            : 'Herunterladen') . '</a>';
                }
                if (
                    $metadataAvailable
                    && ($isImage || $extension === 'pdf' || $isEmail)
                ) {
                    $browserUrl = $isEmail ? $emailUrl : $inlineUrl;
                    echo '<a class="estab-button estab-button-ghost" href="'
                        . estab_message_html($browserUrl)
                        . '" target="_blank" rel="noopener" aria-label="'
                        . estab_message_html(
                            $originalName . ' in neuem Browser-Tab ansehen'
                        ) . '">'
                        . ($isEmail ? 'E-Mail ansehen' : 'Im Browser ansehen')
                        . '</a>';
                }
                if ($editable) {
                    echo '<button type="submit" '
                        . 'name="message_attachment_remove_x" value="'
                        . estab_message_html($reference) . '" '
                        . 'class="estab-button estab-button-danger" '
                        . 'aria-label="' . estab_message_html(
                            $originalName . ' vom Vordruck entfernen'
                        ) . '" formnovalidate>Vom Vordruck entfernen</button>';
                }
                if ($metadataAvailable || $editable) {
                    echo '</div>';
                }
                echo '</div>';
                if ($metadataAvailable && $extension === 'pdf') {
                    $hasEmbeddedPdf = true;
                    echo '<details class="estab-message-attachment-pdf" '
                        . 'data-estab-pdf-preview>'
                        . '<summary aria-label="' . estab_message_html(
                            'PDF ' . $originalName . ' hier anzeigen'
                        ) . '">PDF hier anzeigen</summary>'
                        . '<iframe loading="lazy" '
                        . 'referrerpolicy="no-referrer" data-src="'
                        . estab_message_html($inlineUrl)
                        . '" title="PDF-Vorschau: '
                        . estab_message_html($originalName) . '"></iframe>'
                        . '</details>';
                }
                if ($metadataAvailable && $isEmail) {
                    $hasEmbeddedEmail = true;
                    echo '<details class="estab-message-attachment-email" '
                        . 'data-estab-email-preview>'
                        . '<summary aria-label="' . estab_message_html(
                            'E-Mail ' . $originalName . ' hier anzeigen'
                        ) . '">E-Mail hier anzeigen</summary>'
                        . '<iframe loading="lazy" '
                        . 'referrerpolicy="no-referrer" data-src="'
                        . estab_message_html($emailUrl)
                        . '" title="E-Mail-Ansicht: '
                        . estab_message_html($originalName) . '"></iframe>'
                        . '</details>';
                }
                echo '</article>';
            }
            echo '</div>';
        }
        if ($hasAttachmentFeedback || $hasEmbeddedPdf || $hasEmbeddedEmail) {
            echo <<<'HTML'
<script data-estab-attachment-presentation>
(function () {
  "use strict";
  document.querySelectorAll(
    "[data-estab-pdf-preview], [data-estab-email-preview]"
  ).forEach(function (details) {
    var frame = details.querySelector("iframe[data-src]");
    if (!frame) return;
    details.addEventListener("toggle", function () {
      if (!details.open || frame.hasAttribute("src")) return;
      frame.setAttribute("src", frame.getAttribute("data-src"));
      frame.removeAttribute("data-src");
    });
  });
  var feedback = document.querySelector("[data-estab-attachment-feedback]");
  if (!feedback) return;
  window.requestAnimationFrame(function () {
    feedback.focus({ preventScroll: true });
    feedback.scrollIntoView({ block: "start" });
  });
})();
</script>
HTML;
        }
        if ($editable && is_string($directActionToken)) {
            echo <<<'HTML'
<script data-estab-attachment-upload-limit>
(function () {
  "use strict";
  var input = document.getElementById("message-attachment-upload");
  var error = document.getElementById("message-attachment-upload-error");
  if (!input || !error || !input.form) return;
  var maximum = Number(input.getAttribute("data-estab-max-bytes"));
  if (!Number.isSafeInteger(maximum) || maximum < 1) return;
  function selectedFileIsTooLarge() {
    return input.files && input.files.length > 0
      && Number.isFinite(input.files[0].size)
      && input.files[0].size > maximum;
  }
  function formatMebibytes(bytes) {
    return new Intl.NumberFormat("de-DE", { maximumFractionDigits: 1 })
      .format(bytes / 1048576) + " MiB";
  }
  function validateSelection(moveFocus) {
    var invalid = selectedFileIsTooLarge();
    error.hidden = !invalid;
    error.textContent = invalid
      ? "Die Datei ist größer als die erlaubten "
        + formatMebibytes(maximum)
        + ". Ihre Eingaben bleiben erhalten; wählen Sie eine kleinere Datei."
      : "";
    input.setAttribute("aria-invalid", invalid ? "true" : "false");
    if (invalid && moveFocus) {
      input.focus({ preventScroll: true });
      input.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    return !invalid;
  }
  input.addEventListener("change", function () {
    validateSelection(false);
  });
  input.form.addEventListener("submit", function (event) {
    var submitterName = event.submitter && event.submitter.name
      ? event.submitter.name
      : "";
    if (
      submitterName !== ""
      && submitterName !== "message_attachment_upload_x"
      && submitterName !== "absenden_x"
    ) return;
    if (!validateSelection(true)) event.preventDefault();
  });
})();
</script>
HTML;
        }
        echo '</section>';
    }

    /**
     * Render only the incident-local TBB evidence number linked to this
     * message. The historic 04_nummer remains a technical workflow/archive
     * value and must never be presented as the TBB book number.
     */
    function official_message_ttb_evidence_text(): string
    {
        $value = $this->formdata['estab_ttb_lfd'] ?? null;
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (
            is_string($value)
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1
        ) {
            $parsed = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
            );
            if (is_int($parsed)) {
                return (string) $parsed;
            }
        }
        return 'noch kein TBB-Nachweis';
    }

    /** @return array<int, array{title:string,text:string}> */
    function official_message_help_definitions(): array
    {
        return [
            1 => [
                'title' => 'Tatsächlich verwendetes Übermittlungsmittel',
                'text' => 'Geben Sie an, über welches TK-Mittel die Nachricht tatsächlich empfangen oder gesendet wurde: Funk, Telefon, Telefax, DFÜ oder Kurier/Melder. Feld 1 dokumentiert den tatsächlichen Weg; Feld 7 enthält nur den gewünschten Weg.',
            ],
            2 => [
                'title' => 'Aufnahmevermerk',
                'text' => 'Nur für eingehende Nachrichten. Tragen Sie das Datum mindestens zweistellig, die vierstellige Quittungszeit und Ihr Namenszeichen ein. Mit dem Namenszeichen bestätigen Sie die Aufnahme der Nachricht.',
            ],
            3 => [
                'title' => 'Annahmevermerk',
                'text' => 'Nur für ausgehende Nachrichten. Sobald die Fm-Zentrale die Nachricht zur Beförderung annimmt, trägt sie Uhrzeit und Namenszeichen ein. Die Anleitung verlangt hier kein zusätzliches Pflichtdatum.',
            ],
            4 => [
                'title' => 'Beförderungsvermerk',
                'text' => 'Nur für ausgehende Nachrichten. Tragen Sie das Datum mindestens zweistellig, die vierstellige Quittungszeit der Gegenstelle und Ihr Namenszeichen ein. Bei Beförderung durch Melder gilt die Entgegennahme durch den Melder als Quittierung.',
            ],
            5 => [
                'title' => 'Technisches Betriebsbuch',
                'text' => 'Kennzeichnen Sie die Nachricht als Eingang oder Ausgang und übernehmen Sie die laufende Nummer aus dem Technischen Betriebsbuch. Die Nummer ist keine unabhängige Formularnummer.',
            ],
            6 => [
                'title' => 'Rufname der Gegenstelle / Spruchkopf',
                'text' => 'Tragen Sie hier den Funkrufnamen der Gegenstelle ein. Verwenden Sie nicht ersatzweise einen Eigennamen oder die Anschrift.',
            ],
            7 => [
                'title' => 'Gewünschtes TK-Mittel',
                'text' => 'Sie können hier einen Hinweis geben, über welches TK-Mittel die Nachricht befördert werden soll. Der tatsächlich benutzte Weg wird in Feld 1 nachgewiesen.',
            ],
            8 => [
                'title' => 'DURCHSAGE / Spruch',
                'text' => 'Kennzeichnen Sie die Nachricht als DURCHSAGE oder als Spruch (Ausnahme).',
            ],
            9 => [
                'title' => 'Vorrangstufe',
                'text' => 'Tragen Sie die gewünschte oder bei Eingang erhaltene Vorrangstufe ein: Sofort, Blitz oder Staatsnot. Staatsnot darf nur auf ausdrückliche Weisung einer hierzu berechtigten Stelle verwendet werden. Ohne besondere Vorrangstufe bleibt dieses Feld frei.',
            ],
            10 => [
                'title' => 'Anschrift',
                'text' => 'Immer ausfüllen. Verwenden Sie ausschließlich Dienststellen-, Teileinheits- oder Einheitsbezeichnungen. Eigennamen sind nicht zulässig.',
            ],
            11 => [
                'title' => 'Ruf Nr.',
                'text' => 'Tragen Sie die Rufnummer der Gegenstelle ein. Dieses Feld wird in der Regel bei Gesprächsnotizen verwendet.',
            ],
            12 => [
                'title' => 'Gesprächsnotiz',
                'text' => 'Kreuzen Sie Gesprächsnotiz an, wenn Sie ein Gespräch eigenständig übermittelt, aufgenommen oder notiert haben. Die oben gewählte Übermittlungsart dokumentiert dieses ursprüngliche Gespräch. Nach der formalen Sichtung ergänzt LdF Rufname und Beförderungsweg; der Fernmelder übernimmt anschließend den Beförderungsnachweis.',
            ],
            13 => [
                'title' => 'Inhalt – Betreff',
                'text' => 'Der Bereich Inhalt ist immer auszufüllen. Tragen Sie hier den kurzen Betreff der Nachricht ein. Der ausführliche Nachrichtentext gehört in das folgende Feld.',
            ],
            14 => [
                'title' => 'Nachricht / Text',
                'text' => 'Der Bereich Inhalt ist immer auszufüllen. Fassen Sie den Nachrichtentext so kurz wie möglich. Schreiben Sie klar und lesbar, bei Bedarf in Blockschrift; Zeilenumbrüche bleiben in der digitalen Fassung erhalten.',
            ],
            15 => [
                'title' => 'Absender',
                'text' => 'Immer ausfüllen. Tragen Sie den Absender als Dienststellen-, Teileinheits- oder Einheitsbezeichnung ein. Eigennamen sind nicht zulässig. Bei Eingang ergänzt LdF den Absender aus dem Rufnamen.',
            ],
            16 => [
                'title' => 'Abfassungszeit',
                'text' => 'Immer ausfüllen. Tragen Sie die Abfassungszeit der Nachricht mindestens vierstellig ein. Sie ist nicht automatisch mit Absende- oder Quittungszeit identisch.',
            ],
            17 => [
                'title' => 'Zeichen / Funktion',
                'text' => 'Immer ausfüllen. Beglaubigen Sie die Nachricht mit Ihrem Namenszeichen und Ihrer Funktion. eStab übernimmt diese Angaben soweit möglich aus der für diesen Arbeitsschritt wirksamen Funktion.',
            ],
            18 => [
                'title' => 'Quittung',
                'text' => 'Als Empfänger oder Sichter quittieren Sie den Erhalt der Nachricht mit vierstelliger Uhrzeit und Namenszeichen.',
            ],
            19 => [
                'title' => 'Verteiler TEL / EL / EAL / UEAL',
                'text' => 'Legen Sie durch Ankreuzen den Verteiler der ein- oder ausgehenden Nachricht innerhalb des Stabes fest.',
            ],
            20 => [
                'title' => 'Vermerke / Erledigung',
                'text' => 'Hier können zusätzliche Bearbeitungsvermerke eingetragen werden, die der Vordruck sonst nicht erfasst, zum Beispiel weitere Seiten oder Anlagennummern.',
            ],
        ];
    }

    function official_message_help(int $number): void
    {
        $definition = $this->official_message_help_definitions()[$number];
        $buttonId = 'estab-form-help-button-' . $number;
        $dialogId = 'estab-form-help-' . $number;
        $titleId = $dialogId . '-title';
        $descriptionId = $dialogId . '-description';
        echo '<span class="estab-official-help-anchor">';
        echo '<button id="' . $buttonId . '" type="button" '
            . 'class="estab-official-help-button" '
            . 'data-estab-form-help="' . $number . '" '
            . 'aria-expanded="false" aria-controls="' . $dialogId . '" '
            . 'aria-label="Ausfüllhilfe ' . $number . ' zu '
            . estab_message_html($definition['title']) . ' öffnen">i</button>';
        echo '<span id="' . $dialogId . '" '
            . 'class="estab-official-help-dialog" role="dialog" '
            . 'tabindex="-1" '
            . 'aria-modal="false" aria-labelledby="' . $titleId . '" '
            . 'aria-describedby="' . $descriptionId . '" hidden>';
        echo '<strong id="' . $titleId . '">'
            . $number . ' · ' . estab_message_html($definition['title'])
            . '</strong>';
        echo '<span id="' . $descriptionId . '">'
            . estab_message_html($definition['text']) . '</span>';
        echo '<button type="button" class="estab-official-help-close" '
            . 'data-estab-form-help-close="' . $number . '">Schließen</button>';
        echo '</span></span>';
    }

    function official_message_error(string $field): void
    {
        if (($this->errorselect[$field] ?? true) === false) {
            echo '<span class="estab-official-field-error" role="alert">'
                . 'Eingabe prüfen</span>';
        }
    }

    function official_message_text_input(
        string $field,
        bool $editable,
        int $maxlength,
        string $label,
        string $extraAttributes = '',
        bool $submitReadonly = true
    ): void {
        $value = $this->safe_message_value($field);
        $displayValue = $value;
        if (!$editable && $field === '14_funktion') {
            $displayValue = estab_message_html(estab_function_display_name(
                (string)($this->formdata[$field] ?? '')
            ));
        }
        $invalid = ($this->errorselect[$field] ?? true) === false;
        if ($editable) {
            echo '<input id="f_' . $field . '" class="estab-official-input" '
                . 'name="' . $field . '" value="' . $value . '" '
                . 'maxlength="' . $maxlength . '" '
                . 'aria-label="' . estab_message_html($label) . '"'
                . ($invalid ? ' aria-invalid="true"' : '')
                . $extraAttributes . '>';
            $this->official_message_error($field);
            return;
        }
        if ($submitReadonly) {
            echo '<input id="f_' . $field . '_value" type="hidden" '
                . 'name="' . $field . '" value="' . $value . '">';
        }
        echo '<span id="f_' . $field . '" class="estab-official-readonly" '
            . 'data-estab-readonly="true" aria-label="'
            . estab_message_html($label . ' schreibgeschützt') . '">'
            . ($displayValue === '' ? '&nbsp;' : $displayValue) . '</span>';
    }

    function official_message_textarea(
        string $field,
        bool $editable,
        string $label,
        int $maxlength = 0,
        bool $submitReadonly = true
    ): void {
        $value = $this->safe_message_value($field);
        $invalid = ($this->errorselect[$field] ?? true) === false;
        if ($editable) {
            echo '<textarea id="f_' . $field . '" '
                . 'class="estab-official-textarea" '
                . 'aria-label="' . estab_message_html($label) . '"'
                . ($maxlength > 0 ? ' maxlength="' . $maxlength . '"' : '')
                . ($invalid ? ' aria-invalid="true"' : '')
                . ' name="' . $field . '">' . $value . '</textarea>';
            $this->official_message_error($field);
            return;
        }
        if ($submitReadonly) {
            echo '<input id="f_' . $field . '_value" type="hidden" '
                . 'name="' . $field . '" value="' . $value . '">';
        }
        echo '<span id="f_' . $field . '" '
            . 'class="estab-official-readonly estab-official-readonly--multiline" '
            . 'data-estab-readonly="true" aria-label="'
            . estab_message_html($label . ' schreibgeschützt') . '">'
            . ($value === '' ? '&nbsp;' : nl2br($value)) . '</span>';
    }

    /**
     * @param list<array{value:string,label:string,id:string}> $options
     */
    function official_message_radio_group(
        string $field,
        array $options,
        bool $editable,
        string $label,
        bool $submitReadonly = true,
        bool $controlsEnabled = true,
        bool $required = false,
        string $groupId = '',
        string $describedBy = ''
    ): void {
        $current = (string)($this->formdata[$field] ?? '');
        if (!$editable && $submitReadonly) {
            echo '<input id="f_' . $field . '_value" type="hidden" '
                . 'name="' . $field . '" value="'
                . estab_message_html($current) . '">';
        }
        $disabled = !$editable || !$controlsEnabled;
        echo '<span class="estab-official-choice-group" role="radiogroup" '
            . ($groupId !== ''
                ? 'id="' . estab_message_html($groupId) . '" '
                : '')
            . 'aria-label="' . estab_message_html($label) . '"'
            . ($describedBy !== ''
                ? ' aria-describedby="'
                    . estab_message_html($describedBy) . '"'
                : '')
            . ($disabled ? ' aria-disabled="true"' : '')
            . ($editable && $required ? ' aria-required="true"' : '')
            . '>';
        foreach ($options as $option) {
            $checked = hash_equals($current, $option['value'])
                ? ' checked="checked"'
                : '';
            echo '<label for="f_' . $field . '_' . $option['id'] . '">'
                . '<input id="f_' . $field . '_' . $option['id'] . '" '
                . ($editable ? 'name="' . $field . '" ' : '')
                . 'value="' . estab_message_html($option['value']) . '" '
                . 'type="radio"'
                . $checked
                . ' class="estab-official-box-choice"'
                . ($disabled ? ' disabled' : '')
                . ($editable && !$disabled && $required ? ' required' : '')
                . '>'
                . '<span>' . estab_message_html($option['label']) . '</span>'
                . '</label>';
        }
        echo '</span>';
    }

    /** @return list<array{value:string,label:string,id:string}> */
    function official_message_medium_options(string $field): array
    {
        $current = (string)($this->formdata[$field] ?? '');
        return [
            ['value' => 'Fu', 'label' => 'Funk', 'id' => 'fu'],
            ['value' => 'Fe', 'label' => 'Telefon', 'id' => 'fe'],
            ['value' => 'FAX', 'label' => 'Telefax', 'id' => 'fax'],
            [
                'value' => $current === 'FS' ? 'FS' : '@',
                'label' => 'DFÜ',
                'id' => $current === 'FS' ? 'fs' : 'at',
            ],
            ['value' => 'Me', 'label' => 'Kurier/Melder', 'id' => 'me'],
        ];
    }

    function official_message_checkbox(
        string $field,
        bool $editable,
        string $label,
        bool $submitReadonly = true
    ): void {
        $current = (string)($this->formdata[$field] ?? '');
        $checked = in_array($current, ['t', '1', 'on'], true);
        if (!$editable && $submitReadonly) {
            echo '<input id="f_' . $field . '_value" type="hidden" '
                . 'name="' . $field . '" value="'
                . estab_message_html($current) . '">';
        }
        echo '<label class="estab-official-standalone-check" for="f_'
            . $field . '"><input id="f_' . $field . '" '
            . 'class="estab-official-box-choice" type="checkbox" '
            . ($editable ? 'name="' . $field . '" ' : '')
            . ($editable ? '' : 'disabled ')
            . ($checked ? 'checked ' : '')
            . 'aria-label="' . estab_message_html($label) . '"></label>';
    }

    /** @return array{date:string,time:string} */
    function official_message_stamp_parts(
        string $value,
        bool $preferTimeOnly = false
    ): array {
        $value = trim($value);
        if ($value === '') {
            return ['date' => '', 'time' => ''];
        }

        if (preg_match(
            '/^(\d{2})(\d{4})([[:alpha:]ÄÖÜäöü]{3}\d{4})$/uD',
            $value,
            $matches
        ) === 1) {
            return [
                'date' => $preferTimeOnly ? '' : $matches[1] . $matches[3],
                'time' => $matches[2],
            ];
        }
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/D',
            $value,
            $matches
        ) === 1) {
            return [
                'date' => $preferTimeOnly
                    ? ''
                    : $matches[3] . '.' . $matches[2] . '.' . $matches[1],
                'time' => $matches[4] . ':' . $matches[5],
            ];
        }
        if (preg_match(
            '/^(\d{2}\.\d{2}\.\d{4}|\d{8})\s+'
                . '((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/D',
            $value,
            $matches
        ) === 1) {
            return [
                'date' => $preferTimeOnly ? '' : $matches[1],
                'time' => $matches[2],
            ];
        }
        if (preg_match(
            '/^((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/D',
            $value,
            $matches
        ) === 1) {
            return ['date' => '', 'time' => $matches[1]];
        }
        if (preg_match(
            '/^(.*?)\s+((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/D',
            $value,
            $matches
        ) === 1) {
            return [
                'date' => $preferTimeOnly ? '' : trim($matches[1]),
                'time' => $matches[2],
            ];
        }

        return $preferTimeOnly
            ? ['date' => '', 'time' => $value]
            : ['date' => $value, 'time' => ''];
    }

    function official_message_timestamp_block(
        string $title,
        int $printedNumber,
        int $helpNumber,
        string $timeField,
        string $markField,
        bool $editable,
        string $timeLabel,
        string $markLabel
    ): void {
        echo '<section class="estab-official-stamp">'
            . '<div class="estab-official-cell-heading">'
            . estab_message_html($title);
        $this->official_message_help($helpNumber);
        echo '</div><div class="estab-official-stamp-entry">';
        $markBound = in_array(
            $this->task,
            [
                'FM-Eingang',
                'FM-Eingang_Anhang',
                'Stab_gesprnoti',
                'LdF-Eingang',
                'LdF-Ausgang',
                'FM-Ausgang',
            ],
            true
        );
        $timeOnly = $timeField === '02_zeit';
        $stampParts = $this->official_message_stamp_parts(
            (string)($this->formdata[$timeField] ?? ''),
            $timeOnly
        );
        echo '<div class="estab-official-stamp-datetime'
            . ($timeOnly ? ' estab-official-stamp-datetime--time-only' : '')
            . '" '
            . 'data-estab-single-backend-field="'
            . estab_message_html($timeField) . '" role="group" '
            . 'data-estab-stamp-time-only="'
            . ($timeOnly ? 'true' : 'false') . '" '
            . 'aria-label="' . estab_message_html($timeLabel) . '" '
            . 'aria-describedby="estab-stamp-description-'
            . estab_message_html($timeField) . '">';
        $this->official_message_text_input(
            $timeField,
            $editable,
            19,
            $timeLabel,
            ' inputmode="numeric" autocomplete="off" aria-describedby="'
                . 'estab-stamp-description-' . $timeField . '"',
            false
        );
        echo '<span id="estab-stamp-description-' . $timeField . '" '
            . 'class="estab-visually-hidden">Ein gemeinsames Eingabefeld '
            . 'liefert Datum und Uhrzeit für die beiden getrennt '
            . 'dargestellten Zellen.</span>'
            . '<span class="estab-official-stamp-visual-cell '
            . 'estab-official-stamp-visual-cell--date" '
            . 'data-estab-stamp-cell="datum" aria-hidden="true">'
            . '<span data-estab-stamp-value="date">'
            . estab_message_html($stampParts['date']) . '</span></span>'
            . '<span class="estab-official-stamp-visual-cell '
            . 'estab-official-stamp-visual-cell--time" '
            . 'data-estab-stamp-cell="uhrzeit" aria-hidden="true">'
            . '<span data-estab-stamp-value="time">'
            . estab_message_html($stampParts['time']) . '</span></span>'
            . '</div><div class="estab-official-stamp-mark" '
            . 'data-estab-stamp-cell="hdz">';
        if ($editable && $markBound) {
            echo '<strong id="f_' . $markField . '" '
                . 'data-estab-readonly="true" '
                . 'class="estab-official-readonly" aria-label="'
                . estab_message_html(
                    $markLabel . ' wird aus der Anmeldung übernommen'
                ) . '">' . $this->safe_message_value($markField)
                . '</strong>';
        } else {
            $this->official_message_text_input(
                $markField,
                $editable,
                6,
                $markLabel,
                ' autocomplete="off"',
                false
            );
        }
        echo '</div></div>'
            . '<div class="estab-official-stamp-labels" aria-hidden="true">'
            . '<span>Datum</span><span>Uhrzeit</span><span>Hdz.</span>'
            . '</div><span class="estab-official-print-number">'
            . $printedNumber . '</span></section>';
    }

    function official_message_priority(): void
    {
        $editable = (bool)$this->feld[9];
        $current = (string)($this->formdata['09_vorrangstufe'] ?? '');
        if (!$editable) {
            echo '<input type="hidden" name="09_vorrangstufe" value="'
                . estab_message_html($current) . '">';
        }
        echo '<span class="estab-official-choice-group '
            . 'estab-official-priority-choices" role="radiogroup" '
            . 'aria-label="Vorrangstufe" '
            . 'aria-describedby="estab-form-help-9-description">';
        $options = [
            [
                'value' => $current === 'eee' ? 'eee' : '',
                'label' => 'keine',
                'id' => 'keine',
                'warning' => '',
                'clear' => true,
            ],
        ];
        foreach (estab_message_priority_options() as $option) {
            if ($option['value'] === '') {
                continue;
            }
            $options[] = [
                'value' => $option['value'],
                'label' => $option['label'],
                'id' => match ($option['value']) {
                    'sss' => 'sofort',
                    'bbb' => 'blitz',
                    'aaa' => 'staatsnot',
                    default => $option['value'],
                },
                'warning' => $option['warning'],
                'clear' => false,
            ];
        }
        foreach ($options as $option) {
            $isNone = in_array($current, ['', 'eee'], true)
                && $option['clear'];
            $isSelected = $isNone
                || (!$option['clear'] && $current === $option['value']);
            echo '<label'
                . ($option['clear']
                    ? ' class="estab-official-priority-clear"'
                    : '')
                . ' for="f_09_vorrangstufe_' . $option['id'] . '">'
                . '<input id="f_09_vorrangstufe_' . $option['id'] . '" '
                . 'class="estab-official-box-choice" type="radio" '
                . ($editable ? 'name="09_vorrangstufe" ' : '')
                . 'value="' . $option['value'] . '"'
                . ($editable ? '' : ' disabled')
                . ($isSelected ? ' checked' : '')
                . ($option['warning'] !== ''
                    ? ' aria-describedby="estab-form-help-9-description"'
                        . ' title="'
                        . estab_message_html($option['warning']) . '"'
                    : '')
                . '><span>' . $option['label'] . '</span></label>';
        }
        echo '</span>';
    }

    function official_message_distribution_readonly(): bool
    {
        $immutableAdmin = in_array(
            $this->task,
            ['FM-Admin', 'SI-Admin'],
            true
        );
        return $immutableAdmin
            || !$this->feld[16]
            || (
                $this->task === 'Stab_sichten'
                && ($this->formdata['04_richtung'] ?? '') === 'A'
            );
    }

    /**
     * @return array<string,array{function:string,copies:list<string>}>
     */
    function official_message_stored_recipients(): array
    {
        $distribution = (string)($this->formdata['16_empf'] ?? '');
        $recipients = [];
        foreach (explode(',', $distribution) as $token) {
            $token = trim($token);
            if (
                preg_match(
                    '/\A(.+)_(bl|gn|rt|ge|gb)\z/Di',
                    $token,
                    $parts
                ) !== 1
            ) {
                continue;
            }
            $function = trim($parts[1]);
            $colour = strtolower($parts[2]);
            if ($function === '') {
                continue;
            }
            $key = strtoupper($function);
            $recipients[$key] ??= [
                'function' => $function,
                'copies' => [],
            ];
            if (!in_array($colour, $recipients[$key]['copies'], true)) {
                $recipients[$key]['copies'][] = $colour;
            }
        }
        return $recipients;
    }

    /** @return list<string> */
    function official_message_recipient_copies(string $function): array
    {
        $recipient = $this->official_message_stored_recipients()[
            strtoupper($function)
        ] ?? null;
        if (!is_array($recipient)) {
            return [];
        }
        return $recipient['copies'];
    }

    /**
     * @return array{
     *   groups:array<string,list<array<string,mixed>>>,
     *   extras:list<array<string,mixed>>,
     *   all:list<array<string,mixed>>
     * }
     */
    function official_message_distribution_model(): array
    {
        $groups = ['lead' => [], 'adviser' => [], 'liaison' => []];
        $extras = [];
        $all = [];
        $storedRecipients = $this->official_message_stored_recipients();
        $representedFunctions = [];
        $leadDefinitions = [
            0 => ['display' => 'Leiter', 'keys' => ['LS', 'LEITER']],
            1 => ['display' => 'S1', 'keys' => ['S1']],
            2 => ['display' => 'S2', 'keys' => ['S2']],
            3 => ['display' => 'S3', 'keys' => ['S3']],
            4 => ['display' => 'S4', 'keys' => ['S4']],
            5 => ['display' => 'S5', 'keys' => ['S5']],
            6 => ['display' => 'S6', 'keys' => ['S6']],
        ];
        $leadSlots = array_fill(0, count($leadDefinitions), null);
        for ($row = 1; $row <= 5; $row++) {
            for ($column = 1; $column <= 4; $column++) {
                $cell = $this->empfarray[$row][$column] ?? [];
                $function = trim((string)($cell['fkt'] ?? ''));
                if ($function === '') {
                    continue;
                }
                $entry = [
                    'row' => $row,
                    'column' => $column,
                    'function' => $function,
                    'role' => (string)($cell['rolle'] ?? ''),
                    'copies' => $this->official_message_recipient_copies(
                        $function
                    ),
                ];
                $representedFunctions[strtoupper($function)] = true;
                $all[] = $entry;
                if ($entry['role'] === 'FB') {
                    $groups['adviser'][] = $entry;
                } elseif (
                    str_starts_with(strtoupper($function), 'VB')
                    || str_starts_with(strtoupper($function), 'VERB')
                ) {
                    $groups['liaison'][] = $entry;
                } else {
                    $leadKey = strtoupper(
                        preg_replace('/\s+/u', '', $function) ?? $function
                    );
                    $leadPosition = null;
                    foreach ($leadDefinitions as $position => $definition) {
                        if (in_array($leadKey, $definition['keys'], true)) {
                            $leadPosition = $position;
                            break;
                        }
                    }
                    if (
                        $leadPosition !== null
                        && $leadSlots[$leadPosition] === null
                    ) {
                        $entry['display'] =
                            $leadDefinitions[$leadPosition]['display'];
                        $leadSlots[$leadPosition] = $entry;
                    } else {
                        $entry['display'] = $function;
                        $extras[] = $entry;
                    }
                }
            }
        }
        foreach ($leadDefinitions as $position => $definition) {
            if ($leadSlots[$position] !== null) {
                $groups['lead'][] = $leadSlots[$position];
                continue;
            }
            $storedLead = null;
            foreach ($definition['keys'] as $leadKey) {
                if (isset($storedRecipients[$leadKey])) {
                    $storedLead = $storedRecipients[$leadKey];
                    $representedFunctions[$leadKey] = true;
                    break;
                }
            }
            $groups['lead'][] = [
                'display' => $definition['display'],
                'function' => (string)(
                    $storedLead['function'] ?? $definition['display']
                ),
                'copies' => is_array($storedLead['copies'] ?? null)
                    ? $storedLead['copies']
                    : [],
                'unavailable' => true,
            ];
        }
        foreach (['adviser', 'liaison'] as $group) {
            if (count($groups[$group]) > 6) {
                $extras = array_merge(
                    $extras,
                    array_slice($groups[$group], 6)
                );
                $groups[$group] = array_slice($groups[$group], 0, 6);
            }
            while (count($groups[$group]) < 6) {
                $groups[$group][] = [
                    'display' => '',
                    'function' => '',
                    'copies' => [],
                    'unavailable' => true,
                ];
            }
        }
        foreach ($storedRecipients as $key => $storedRecipient) {
            if (isset($representedFunctions[$key])) {
                continue;
            }
            $extras[] = [
                'display' => $storedRecipient['function'],
                'function' => $storedRecipient['function'],
                'copies' => $storedRecipient['copies'],
                'historical' => true,
                'unavailable' => true,
            ];
            $representedFunctions[$key] = true;
        }
        return ['groups' => $groups, 'extras' => $extras, 'all' => $all];
    }

    /** @param array<string,mixed> $entry */
    function official_message_recipient_control(
        array $entry,
        bool $readonly
    ): void {
        $display = estab_function_display_name(
            (string)($entry['display'] ?? $entry['function'])
        );
        $copies = is_array($entry['copies'] ?? null)
            ? $entry['copies']
            : [];
        $unavailable = (bool)($entry['unavailable'] ?? false);
        echo '<span class="estab-official-recipient"'
            . ($unavailable ? ' data-estab-recipient-unavailable="true"' : '')
            . '>';
        if ($unavailable && (!$readonly || $copies === [])) {
            echo '<span class="estab-official-recipient-box" '
                . 'aria-hidden="true"></span><span>'
                . ($display === '' ? '&nbsp;' : estab_message_html($display))
                . '</span></span>';
            return;
        }
        $blueChecked = in_array('bl', $copies, true);
        if ($readonly) {
            $copyNames = [
                'bl' => 'blaue',
                'gn' => 'grüne',
                'rt' => 'rote',
                'ge' => 'gelbe',
                'gb' => 'gelbe',
            ];
            $selectedNames = [];
            foreach ($copies as $copy) {
                if (isset($copyNames[$copy])) {
                    $selectedNames[$copyNames[$copy]] = true;
                }
            }
            $copyLabel = $selectedNames === []
                ? 'keine Durchschrift ausgewählt'
                : implode(' und ', array_keys($selectedNames))
                    . ' Durchschrift ausgewählt';
            echo '<input class="estab-official-box-choice '
                . 'estab-official-copy-indicator" type="checkbox" disabled'
                . ($copies === [] ? '' : ' checked')
                . ' aria-label="' . estab_message_html(
                    $display . ', ' . $copyLabel . ', schreibgeschützt'
                ) . '">';
        } else {
            $coordinate = $entry['row'] . $entry['column'];
            echo '<input class="estab-official-box-choice" '
                . 'name="16_' . $coordinate . '" '
                . 'value="16_' . $coordinate . '_bl"'
                . ' type="checkbox"'
                . ($blueChecked ? ' checked' : '')
                . ' aria-label="' . estab_message_html(
                    $display . ' als Empfänger auswählen'
                ) . '">';
        }
        echo '<span>' . estab_message_html($display) . '</span></span>';
    }

    function official_message_distribution(): void
    {
        $readonly = $this->official_message_distribution_readonly();
        $model = $this->official_message_distribution_model();
        $headings = [
            'lead' => 'TEL/EL/EAL/UEAL',
            'adviser' => 'Fachberater',
            'liaison' => 'Verb.stellen',
        ];
        echo '<div class="estab-official-distribution-grid">';
        foreach ($headings as $group => $heading) {
            echo '<section data-estab-recipient-group="' . $group . '">'
                . '<h3>' . $heading . '</h3>';
            if ($group === 'lead') {
                echo '<div class="estab-official-lead-grid">'
                    . '<div class="estab-official-lead-director">';
                $this->official_message_recipient_control(
                    $model['groups'][$group][0],
                    $readonly
                );
                echo '</div><div class="estab-official-lead-sections">';
                foreach (
                    array_slice($model['groups'][$group], 1)
                    as $entry
                ) {
                    $this->official_message_recipient_control(
                        $entry,
                        $readonly
                    );
                }
                echo '</div></div>';
            } else {
                foreach ($model['groups'][$group] as $entry) {
                    $this->official_message_recipient_control(
                        $entry,
                        $readonly
                    );
                }
            }
            echo '</section>';
        }
        echo '</div>';
    }

    function official_message_extra_distribution(): void
    {
        $model = $this->official_message_distribution_model();
        $readonly = $this->official_message_distribution_readonly();
        if ($model['extras'] === []) {
            return;
        }
        echo '<section class="estab-message-distribution-extras" '
            . 'aria-labelledby="estab-message-distribution-extras-title">'
            . '<span class="estab-section-kicker">Digitale Ergänzung</span>'
            . '<h2 id="estab-message-distribution-extras-title">'
            . 'Digitale Verteilung</h2>';
        echo '<h3>Weitere betriebliche Empfänger</h3>'
            . '<p>Diese dynamischen Funktionen gehören nicht zum festen '
            . 'amtlichen Verteilerfeld, bleiben aber vollständig im '
            . 'Nachrichtenlauf erhalten. Auch hier wählt jedes Kästchen '
            . 'direkt einen Empfänger.</p>'
            . '<div class="estab-message-distribution-extra-grid">';
        foreach ($model['extras'] as $entry) {
            $this->official_message_recipient_control($entry, $readonly);
        }
        echo '</div></section>';
    }

    function official_message_actions(string $position): void
    {
        $isTop = $position === 'top';
        echo '<nav class="estab-message-actionbar" aria-label="'
            . ($isTop ? 'Aktionen zum Nachrichtenvordruck' : 'Abschlussaktionen')
            . '" data-estab-message-actions="' . $position . '"';
        if ($this->task === 'Stab_sichten') {
            echo ' data-estab-formal-review="'
                . (($this->formdata['04_richtung'] ?? '') === 'A'
                    ? 'outgoing'
                    : 'incoming')
                . '"';
        }
        echo '>';
        echo '<button type="button" class="estab-button estab-button-secondary" '
            . 'onclick="window.print()">Drucken</button>';
        switch ($this->task) {
            case 'Stab_lesen':
                echo '<button type="submit" name="gelesen_x" value="1" '
                    . 'class="estab-button estab-button-primary">Gelesen / OK</button>';
                if (($this->formdata['04_richtung'] ?? '') === 'E') {
                    echo '<button type="submit" name="antwort_x" value="1" '
                        . 'class="estab-button estab-button-secondary">Antworten</button>';
                }
                echo '<button type="submit" name="weiterleiten_x" value="1" '
                    . 'class="estab-button estab-button-secondary">Weiterleiten</button>';
                break;
            case 'FM-Eingang':
            case 'Stab_schreiben':
            case 'Stab_korrigieren':
            case 'FM-Eingang_Anhang':
            case 'Stab_gesprnoti':
                $attachmentCount = count(
                    $this->official_message_attachment_references()
                );
                echo '<a href="#nachrichtenanlagen" '
                    . 'class="estab-button estab-button-secondary '
                    . 'estab-message-attachment-jump">'
                    . ($attachmentCount > 0
                        ? $attachmentCount . ' '
                            . ($attachmentCount === 1 ? 'Anlage' : 'Anlagen')
                        : 'Anlage hinzufügen')
                    . '</a>';
                echo '<button type="submit" name="absenden_x" value="1" '
                    . 'class="estab-button estab-button-primary">'
                    . ($this->task === 'Stab_gesprnoti'
                        ? 'Zur Sichtung geben'
                        : 'Absenden')
                    . '</button>';
                echo '<button type="submit" name="abbrechen_x" value="1" '
                    . 'class="estab-button estab-button-ghost" formnovalidate>'
                    . 'Abbrechen</button>';
                break;
            case 'FM-Ausgang':
            case 'LdF-Eingang':
            case 'LdF-Ausgang':
                echo '<button type="submit" name="absenden_x" value="1" '
                    . 'class="estab-button estab-button-primary">Bearbeitung abschließen</button>';
                echo '<button type="submit" name="abbrechen_x" value="1" '
                    . 'class="estab-button estab-button-ghost" formnovalidate>'
                    . 'Abbrechen</button>';
                if ($this->task === 'LdF-Ausgang') {
                    echo '<button type="submit" '
                        . 'name="ldf_zurueckweisen_x" value="1" '
                        . 'class="estab-button estab-button-danger" formnovalidate>'
                        . 'An Verfasser zurückgeben</button>';
                }
                if ($this->task === 'FM-Ausgang') {
                    echo '<button type="submit" '
                        . 'name="transport_nicht_moeglich_x" value="1" '
                        . 'class="estab-button estab-button-danger" formnovalidate>'
                        . 'Beförderung nicht möglich</button>';
                    if (($this->formdata['04_richtung'] ?? '') === 'A') {
                        echo '<button type="submit" name="antwort_x" value="1" '
                            . 'class="estab-button estab-button-secondary">'
                            . 'Antworten</button>';
                    }
                }
                break;
            case 'Stab_sichten':
                $outgoing = ($this->formdata['04_richtung'] ?? '') === 'A';
                echo '<button type="submit" name="absenden_x" value="1" '
                    . 'class="estab-button estab-button-primary">'
                    . ($outgoing
                        ? 'Formal geprüft – an FmZt'
                        : 'Sichtung abschließen')
                    . '</button>';
                if ($outgoing) {
                    echo '<button type="submit" name="zurueckweisen_x" value="1" '
                        . 'class="estab-button estab-button-danger">'
                        . 'An Verfasser zurückgeben</button>';
                }
                echo '<button type="submit" name="abbrechen_x" value="1" '
                    . 'class="estab-button estab-button-ghost" formnovalidate>'
                    . 'Abbrechen</button>';
                break;
            case 'FM-Admin':
            case 'SI-Admin':
                echo '<strong class="estab-message-readonly-badge">'
                    . 'Abgeschlossener Nachweis – schreibgeschützt</strong>';
                break;
        }
        if (!$isTop) {
            echo '<a class="estab-button estab-button-ghost" href="#nachrichtenvordruck">'
                . 'Zum Formularanfang</a>';
        }
        echo '</nav>';
        if ($isTop && $this->task === 'Stab_lesen') {
            $this->official_message_categories();
        }
    }

    function official_message_categories(): void
    {
        include_once __DIR__ . '/katego.php';
        include __DIR__ . '/../4fcfg/fkt_rolle.inc.php';
        $recordId = (string)($this->formdata['00_lfd'] ?? '');
        $definitions = [
            'master' => [
                'label' => 'Globale Kategorie',
                'manager' => 'Globale Kategorien verwalten',
            ],
            'fkt' => [
                'label' => 'Funktionskategorie',
                'manager' => 'Funktionskategorien verwalten',
            ],
            'user' => [
                'label' => 'Persönliche Kategorie',
                'manager' => 'Persönliche Kategorien verwalten',
            ],
        ];
        echo '<details class="estab-message-categories">'
            . '<summary>Kategorien und Ablage</summary>'
            . '<div class="estab-message-category-grid">';
        $actingFunction = '';
        foreach ($definitions as $type => $definition) {
            $categories = new kategorien($type);
            $actingFunction = (string) $categories->stab_fkt;
            $selected = $categories->db_get_kategobymsg($recordId);
            $managerUrl = 'katgoedt.php?' . http_build_query(
                [
                    'dbtyp' => $type,
                    'msgno' => $recordId,
                    'acting_function' => $actingFunction,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
            $allowed = $type !== 'master'
                || estab_category_can_manage_master(
                    ['funktion' => $actingFunction],
                    isset($redcopy2) && is_string($redcopy2)
                        ? $redcopy2
                        : null
                );
            echo '<section><strong>' . $definition['label'] . '</strong>';
            if ($allowed || $type !== 'master') {
                echo '<a href="' . estab_message_html($managerUrl) . '">'
                    . $definition['manager'] . '</a>';
            }
            if (($selected['kategorie'] ?? '') !== '') {
                echo '<span class="estab-message-category-current">'
                    . '<strong>'
                    . estab_message_html($selected['kategorie'])
                    . '</strong>';
                if ((string)($selected['beschreibung'] ?? '') !== '') {
                    echo '<small class="estab-message-category-description">'
                        . estab_auth_html($selected['beschreibung'])
                        . '</small>';
                }
                echo '</span>';
            }
            if ($allowed) {
                $categories->pulldown_kategorien(
                    $selected['lfd'] ?? '',
                    true,
                    'top'
                );
            }
            echo '</section>';
        }
        $assignmentAction = 'katgoedt.php?' . http_build_query(
            ['acting_function' => $actingFunction],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        echo '<button type="submit" name="category_action" value="assign" '
            . 'formaction="' . estab_message_html($assignmentAction)
            . '" class="estab-button '
            . 'estab-button-secondary">Kategorien zuordnen</button>';
        echo '</div></details>';
    }

    function official_message_workflow_controls(): void
    {
        $hasTransport = in_array(
            $this->task,
            ['Stab_gesprnoti', 'LdF-Eingang', 'LdF-Ausgang', 'FM-Ausgang'],
            true
        ) || (bool)($this->feld[6] ?? false);
        if (!$hasTransport) {
            return;
        }
        echo '<section class="estab-message-workflow-panel" '
            . 'aria-labelledby="estab-message-workflow-title">';
        if ($this->task === 'Stab_gesprnoti') {
            echo '<div><span class="estab-section-kicker">Weiterer Nachrichtenlauf</span>'
                . '<h2 id="estab-message-workflow-title">Gesprächsnotiz zur Sichtung geben</h2>'
                . '<p>Die im Vordruck ausgewählte Übermittlungsart beschreibt das '
                . 'ursprüngliche Gespräch. Rufname und Beförderungsweg werden erst '
                . 'in den folgenden Bearbeitungsschritten ergänzt.</p></div>';
        } else {
            echo '<div><span class="estab-section-kicker">Digitale Bearbeitung</span>'
                . '<h2 id="estab-message-workflow-title">Betriebliche Ergänzungen</h2>'
                . '<p>Diese Angaben steuern den digitalen Ablauf und liegen deshalb '
                . 'außerhalb des unveränderten amtlichen Rasters.</p></div>';
        }
        echo '<div class="estab-message-workflow-fields">';
        if ($this->task === 'Stab_gesprnoti') {
            echo '<fieldset data-estab-conversation-next-steps>'
                . '<legend>Nächste Schritte</legend><ol>'
                . '<li><strong>Si</strong> prüft die Gesprächsnotiz formal.</li>'
                . '<li><strong>LdF</strong> wählt Rufname und freigegebenen '
                . 'S6-Beförderungsweg.</li>'
                . '<li><strong>Fernmelder</strong> übernimmt die Nachricht und '
                . 'führt den Beförderungsnachweis.</li>'
                . '</ol></fieldset>';
        }
        if ($this->task === 'LdF-Eingang') {
            $original = (string)(
                $this->formdata['incoming_transport_original_medium'] ?? ''
            );
            echo '<fieldset><legend>Eingangsweg durch LdF bestätigen</legend>'
                . '<p data-estab-incoming-transport-original="'
                . estab_message_html($original)
                . '">Vom Fernmelder erfasst: <strong>'
                . estab_message_html(estab_message_medium_text($original))
                . '</strong></p>';
            echo '<label><input type="checkbox" '
                . 'id="f_incoming_transport_confirmed" '
                . 'name="incoming_transport_confirmed" value="1" required'
                . ' data-estab-incoming-transport-confirmation="required"'
                . ((string)($this->formdata['incoming_transport_confirmed'] ?? '') === '1'
                    ? ' checked'
                    : '')
                . '> Eingangsweg geprüft und bestätigt</label>';
            echo '<label for="f_incoming_transport_correction_reason">'
                . 'Begründung nur bei Änderung</label>'
                . '<textarea id="f_incoming_transport_correction_reason" '
                . 'name="incoming_transport_correction_reason" maxlength="500" '
                . 'rows="2">'
                . $this->safe_message_value(
                    'incoming_transport_correction_reason'
                ) . '</textarea></fieldset>';
        }
        if ($this->task === 'LdF-Ausgang') {
            echo '<fieldset><legend>Gültiger S6-Fernmeldeweg</legend>'
                . '<label for="f_fernmeldeplan_eintrag_id">'
                . 'Freigegebenen Fernmeldeweg auswählen</label>'
                . '<select id="f_fernmeldeplan_eintrag_id" '
                . 'name="fernmeldeplan_eintrag_id" required>'
                . '<option value="">Bitte Fernmeldeweg auswählen</option>';
            $selected = (string)(
                $this->formdata['fernmeldeplan_eintrag_id'] ?? ''
            );
            foreach ($this->activeTelecomRoutes as $route) {
                $parts = array_values(array_filter([
                    trim((string)($route['betriebsstelle'] ?? '')),
                    trim((string)($route['rufname'] ?? '')),
                    trim((string)($route['kanal'] ?? '')),
                    trim((string)($route['bandlage'] ?? '')),
                    trim((string)($route['verkehrsform'] ?? '')),
                ], static fn(string $part): bool => $part !== ''));
                $routeId = (string)$route['fernmeldeplan_eintrag_id'];
                $routeLabel = 'Plan v' . (int)$route['plan_version']
                    . ' · ' . estab_dv_telecom_medium_label(
                        $route['medium'] ?? null
                    )
                    . ' · ' . implode(' · ', $parts);
                echo '<option value="' . estab_message_html($routeId) . '"'
                    . ($selected === $routeId ? ' selected' : '') . '>'
                    . estab_message_html($routeLabel) . '</option>';
            }
            echo '</select>';
            if ($this->activeTelecomRoutes === []) {
                echo '<p class="estab-field-error">Kein aktuell gültiger, '
                    . 'freigegebener S6-Fernmeldeplan verfügbar.</p>';
            }
            echo '</fieldset>'
                . '<fieldset data-estab-ldf-return>'
                . '<legend>Begründete Rückgabe</legend>'
                . '<p>Wenn Rufname, Anschrift oder Inhalt fachlich nicht '
                . 'beförderbar sind, geht die Meldung zur Korrektur an den '
                . 'Verfasser und anschließend erneut über Sichter und LdF.</p>'
                . '<label for="f_ldf_rueckgabegrund">Rückgabegrund</label>'
                . '<textarea id="f_ldf_rueckgabegrund" '
                . 'name="ldf_rueckgabegrund" maxlength="2000" rows="3">'
                . $this->safe_message_value('ldf_rueckgabegrund')
                . '</textarea></fieldset>';
        } elseif ((bool)($this->feld[6] ?? false)) {
            echo '<fieldset><legend>Beförderungsweg</legend>';
            $this->official_message_text_input(
                '06_befweg',
                true,
                128,
                'Beförderungsweg'
            );
            echo '</fieldset>';
        }
        if ($this->task === 'FM-Ausgang') {
            $summary = implode(' · ', array_values(array_filter([
                trim((string)$this->formdata['06_befwegausw']),
                trim((string)$this->formdata['06_befweg']),
            ], static fn(string $part): bool => $part !== '')));
            echo '<fieldset data-estab-transport-confirmation="required">'
                . '<legend>Beförderungsnachweis</legend>'
                . '<p><strong>Disponierter S6-Weg:</strong> '
                . estab_message_html($summary) . '</p>'
                . '<label><input id="f_transportweg_bestaetigt" '
                . 'type="checkbox" name="transportweg_bestaetigt" value="1" '
                . 'required'
                . ((string)$this->formdata['transportweg_bestaetigt'] === '1'
                    ? ' checked'
                    : '')
                . '> Nachricht über diesen Weg befördert</label>'
                . '<label for="f_transport_rueckgabegrund">'
                . 'Rückgabegrund, falls nicht möglich</label>'
                . '<textarea id="f_transport_rueckgabegrund" '
                . 'name="transport_rueckgabegrund" maxlength="2000" rows="3">'
                . $this->safe_message_value('transport_rueckgabegrund')
                . '</textarea></fieldset>';
        }
        echo '</div></section>';
    }

    function official_message_help_script(): void
    {
        echo <<<'HTML'
<script data-estab-official-form-help>
(function () {
  "use strict";
  var buttons = Array.prototype.slice.call(
    document.querySelectorAll("[data-estab-form-help]")
  );

  function dialogFor(button) {
    var id = button.getAttribute("aria-controls");
    return id ? document.getElementById(id) : null;
  }

  function close(button, restoreFocus) {
    var dialog = dialogFor(button);
    if (!dialog) {
      return;
    }
    dialog.hidden = true;
    button.setAttribute("aria-expanded", "false");
    if (restoreFocus) {
      button.focus();
    }
  }

  function closeAll(except) {
    for (var index = 0; index < buttons.length; index++) {
      if (buttons[index] !== except) {
        close(buttons[index], false);
      }
    }
  }

  function positionDialog(button, dialog) {
    var margin = 12;
    var gap = 8;
    var buttonRect = button.getBoundingClientRect();
    var dialogRect = dialog.getBoundingClientRect();
    var width = dialogRect.width;
    var height = dialogRect.height;
    var left = Math.min(
      window.innerWidth - width - margin,
      Math.max(margin, buttonRect.right - width)
    );
    var top = buttonRect.bottom + gap;
    if (top + height > window.innerHeight - margin) {
      top = Math.max(margin, buttonRect.top - height - gap);
    }
    dialog.style.left = left + "px";
    dialog.style.top = top + "px";
  }

  function open(button) {
    var dialog = dialogFor(button);
    if (!dialog) {
      return;
    }
    closeAll(button);
    dialog.hidden = false;
    button.setAttribute("aria-expanded", "true");
    positionDialog(button, dialog);
    try {
      dialog.focus({ preventScroll: true });
    } catch (error) {
      dialog.focus();
    }
  }

  function stampParts(value, timeOnly) {
    var normalized = String(value || "").trim();
    var match;
    if (!normalized) {
      return { date: "", time: "" };
    }
    match = normalized.match(/^(\d{2})(\d{4})([A-Za-zÄÖÜäöü]{3}\d{4})$/);
    if (match) {
      return {
        date: timeOnly ? "" : match[1] + match[3],
        time: match[2]
      };
    }
    match = normalized.match(
      /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::\d{2})?$/
    );
    if (match) {
      return {
        date: timeOnly ? "" : match[3] + "." + match[2] + "." + match[1],
        time: match[4] + ":" + match[5]
      };
    }
    match = normalized.match(
      /^(\d{2}\.\d{2}\.\d{4}|\d{8})\s+((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/
    );
    if (match) {
      return { date: timeOnly ? "" : match[1], time: match[2] };
    }
    match = normalized.match(
      /^((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/
    );
    if (match) {
      return { date: "", time: match[1] };
    }
    match = normalized.match(
      /^(.*?)\s+((?:[01]\d|2[0-3]):?[0-5]\d(?::?[0-5]\d)?)$/
    );
    if (match) {
      return { date: timeOnly ? "" : match[1].trim(), time: match[2] };
    }
    return timeOnly
      ? { date: "", time: normalized }
      : { date: normalized, time: "" };
  }

  function updateStamp(stamp) {
    var fieldName = stamp.getAttribute("data-estab-single-backend-field");
    var control = fieldName ? document.getElementById("f_" + fieldName) : null;
    if (!control) {
      return;
    }
    var value = "value" in control ? control.value : control.textContent;
    var parts = stampParts(
      value,
      stamp.getAttribute("data-estab-stamp-time-only") === "true"
    );
    var date = stamp.querySelector('[data-estab-stamp-value="date"]');
    var time = stamp.querySelector('[data-estab-stamp-value="time"]');
    if (date) {
      date.textContent = parts.date;
    }
    if (time) {
      time.textContent = parts.time;
    }
  }

  Array.prototype.forEach.call(
    document.querySelectorAll("[data-estab-single-backend-field]"),
    function (stamp) {
      var fieldName = stamp.getAttribute("data-estab-single-backend-field");
      var control = fieldName
        ? document.getElementById("f_" + fieldName)
        : null;
      updateStamp(stamp);
      if (control && "value" in control) {
        control.addEventListener("input", function () {
          updateStamp(stamp);
        });
      }
    }
  );

  var conversationCheckbox = document.getElementById("f_11_gesprnotiz");
  var conversationMedium = document.querySelector(
    "[data-estab-conversation-medium]"
  );
  if (conversationMedium) {
    var conversationMediumGroup = conversationMedium.querySelector(
      ".estab-official-choice-group"
    );
    var conversationMediumInputs = Array.prototype.slice.call(
      conversationMedium.querySelectorAll(
        'input[type="radio"][name="01_medium"]'
      )
    );
    var conversationMediumStatus = document.querySelector(
      "[data-estab-conversation-medium-status]"
    );
    var conversationMediumControlled = conversationMedium.getAttribute(
      "data-estab-conversation-medium-controlled"
    ) === "true";

    function selectedConversationMediumLabel() {
      for (var selectedIndex = 0;
        selectedIndex < conversationMediumInputs.length;
        selectedIndex++) {
        if (!conversationMediumInputs[selectedIndex].checked) {
          continue;
        }
        var selectedLabel = conversationMediumInputs[selectedIndex].closest(
          "label"
        );
        var selectedText = selectedLabel
          ? selectedLabel.querySelector("span")
          : null;
        return selectedText ? selectedText.textContent.trim() : "";
      }
      return "";
    }

    function updateConversationMediumStatus(active) {
      if (!conversationMediumStatus) {
        return;
      }
      var selectedLabel = selectedConversationMediumLabel();
      if (active && selectedLabel) {
        conversationMediumStatus.textContent = "Ausgewählt: "
          + selectedLabel + ".";
      } else if (active) {
        conversationMediumStatus.textContent =
          "Jetzt oben die Übermittlungsart auswählen.";
      } else {
        conversationMediumStatus.textContent =
          "Ankreuzen aktiviert oben Telefon, Funk, Telefax, DFÜ oder "
          + "Kurier/Melder.";
      }
    }

    function updateConversationMedium() {
      var active = !conversationMediumControlled
        || Boolean(conversationCheckbox && conversationCheckbox.checked);
      conversationMedium.setAttribute(
        "data-estab-conversation-medium-active",
        active ? "true" : "false"
      );
      if (conversationMediumGroup) {
        conversationMediumGroup.setAttribute(
          "aria-disabled",
          active ? "false" : "true"
        );
        conversationMediumGroup.setAttribute(
          "aria-required",
          active ? "true" : "false"
        );
      }
      for (var mediumIndex = 0;
        mediumIndex < conversationMediumInputs.length;
        mediumIndex++) {
        conversationMediumInputs[mediumIndex].disabled = !active;
        conversationMediumInputs[mediumIndex].required = active;
      }
      updateConversationMediumStatus(active);
    }

    for (var conversationMediumIndex = 0;
      conversationMediumIndex < conversationMediumInputs.length;
      conversationMediumIndex++) {
      conversationMediumInputs[conversationMediumIndex].addEventListener(
        "change",
        function () {
          updateConversationMediumStatus(true);
        }
      );
    }
    if (conversationMediumControlled && conversationCheckbox) {
      conversationCheckbox.setAttribute(
        "aria-controls",
        "estab-conversation-medium-options"
      );
      conversationCheckbox.addEventListener(
        "change",
        updateConversationMedium
      );
      updateConversationMedium();
    } else if (conversationMediumStatus) {
      updateConversationMediumStatus(true);
    }
  }

  for (var index = 0; index < buttons.length; index++) {
    (function (button) {
      button.addEventListener("click", function () {
        if (button.getAttribute("aria-expanded") === "true") {
          close(button, true);
        } else {
          open(button);
        }
      });
      var dialog = dialogFor(button);
      if (!dialog) {
        return;
      }
      var closer = dialog.querySelector("[data-estab-form-help-close]");
      if (closer) {
        closer.addEventListener("click", function () {
          close(button, true);
        });
      }
    })(buttons[index]);
  }

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") {
      return;
    }
    for (var index = 0; index < buttons.length; index++) {
      if (buttons[index].getAttribute("aria-expanded") === "true") {
        close(buttons[index], true);
        break;
      }
    }
  });

  document.addEventListener("pointerdown", function (event) {
    for (var index = 0; index < buttons.length; index++) {
      var button = buttons[index];
      var dialog = dialogFor(button);
      if (
        button.getAttribute("aria-expanded") === "true"
        && event.target !== button
        && dialog
        && !dialog.contains(event.target)
      ) {
        close(button, false);
      }
    }
  });

  window.addEventListener("resize", function () {
    for (var index = 0; index < buttons.length; index++) {
      if (buttons[index].getAttribute("aria-expanded") === "true") {
        positionDialog(buttons[index], dialogFor(buttons[index]));
      }
    }
  });
})();
</script>
HTML;
    }

    function plot_official_message_form(): void
    {
        include __DIR__ . '/../4fcfg/config.inc.php';
        include __DIR__ . '/../4fcfg/para.inc.php';
        include __DIR__ . '/../4fcfg/dbcfg.inc.php';
        include __DIR__ . '/../4fcfg/e_cfg.inc.php';
        include __DIR__ . '/../4fcfg/fkt_rolle.inc.php';
        include __DIR__ . '/../4fcfg/color.inc.php';

        $this->ziele();
        switch ($this->fktmsgbgcolor) {
            case 'rt':
                $this->formbgcolor = $cfg['vbg']['rt'];
                break;
            case 'gn':
                $this->formbgcolor = $cfg['vbg']['gn'];
                break;
            case 'bl':
                $this->formbgcolor = $cfg['vbg']['bl'];
                break;
            case 'ge':
                $this->formbgcolor = $cfg['vbg']['ge'];
                break;
            default:
                $this->formbgcolor = $cfg['vbg']['default'];
        }
        $this->feldbgcolor();
        $this->get_access_by_task();

        $officialStylesheet = file_get_contents(
            __DIR__ . '/../estab-ui.css'
        );
        if ($officialStylesheet === false) {
            throw new RuntimeException(
                'Das Stylesheet des Nachrichtenvordrucks ist nicht lesbar.'
            );
        }
        pre_html(
            'N',
            'Nachrichtenvordruck · ' . $this->task . ' '
                . $conf_4f['Titelkurz'] . ' ' . $conf_4f['Version'],
            $officialStylesheet
        );
        echo '<body class="estab-message-form-body">';
        echo '<main class="estab-message-form-page">';
        $attachmentCount = count(
            $this->official_message_attachment_references()
        );
        echo '<header class="estab-message-page-header">'
            . '<div><span class="estab-section-kicker">Nachrichtenwesen</span>'
            . '<h1>Nachrichtenvordruck</h1>'
            . '<p>Amtliches Raster mit feldbezogenen Ausfüllhinweisen. '
            . 'Das Symbol <strong>i</strong> öffnet die jeweilige Anleitung.</p>'
            . '</div><div class="estab-message-header-badges">'
            . '<span class="estab-message-task-badge">'
            . estab_message_html($this->task) . '</span>';
        if ($attachmentCount > 0 || $this->official_message_attachments_editable()) {
            echo '<a class="estab-message-attachment-badge'
                . ($attachmentCount === 0
                    ? ' estab-message-attachment-badge--empty'
                    : '')
                . '" '
                . 'href="#nachrichtenanlagen">'
                . ($attachmentCount > 0
                    ? $attachmentCount . ' '
                        . ($attachmentCount === 1 ? 'Anlage' : 'Anlagen')
                    : 'Anlage hinzufügen')
                . '</a>';
        }
        echo '</div></header>';
        if ((string)$this->formdata['estab_route_error'] !== '') {
            echo '<div class="estab-alert estab-alert--danger" role="alert">'
                . estab_message_html($this->formdata['estab_route_error'])
                . '</div>';
        }
        echo (string)$this->messageTimelineHtml;
        include_once __DIR__ . '/katego.php';
        $dirtyInitial = $this->hasUnsavedValidationData
            ? ' data-estab-dirty-initial'
            : '';
        echo '<form method="post" enctype="multipart/form-data" action="'
            . estab_message_html($conf_4f['MainURL'])
            . '" name="4fach" data-estab-dirty-guard '
            . 'data-estab-requires-incident' . $dirtyInitial . '>';
        echo estab_csrf_field();
        $actingFunction = $this->official_message_acting_function();
        if ($actingFunction !== null) {
            echo '<input type="hidden" name="acting_function" value="'
                . estab_message_html($actingFunction) . '">';
        }
        echo '<input type="hidden" name="recipient_matrix_revision" value="'
            . estab_message_html(
                estab_workflow_recipient_matrix_revision(
                    $empf_matrix,
                    (string)$this->redcopy2
                )
            ) . '">';
        echo '<input type="hidden" name="kate_todo" value="speichern">'
            . '<input type="hidden" name="msglfd" value="'
            . $this->safe_message_value('00_lfd') . '">'
            . '<input type="hidden" name="00_lfd" value="'
            . $this->safe_message_value('00_lfd') . '">';
        echo '<input id="f_12_anhang" type="hidden" name="12_anhang" '
            . 'value="' . $this->safe_message_value('12_anhang') . '">';
        if (!in_array($this->task, ['FM-Admin', 'SI-Admin'], true)) {
            echo '<input type="hidden" name="task" value="'
                . estab_message_html($this->task) . '">';
        }
        $this->official_message_actions('top');
        $this->official_message_workflow_controls();

        echo '<div class="estab-message-form-scroll" tabindex="0" '
            . 'aria-label="Amtlichen Nachrichtenvordruck horizontal verschieben">';
        echo '<article id="nachrichtenvordruck" '
            . 'class="estab-official-message-form" '
            . 'data-estab-official-message-form data-estab-form-zones="3">';

        echo '<section class="estab-official-zone estab-official-zone--fmz" '
            . 'data-estab-form-zone="fm-zentrale">'
            . '<h2 class="estab-official-vertical-title">Fm-Zentrale</h2>'
            . '<div class="estab-official-fmz-grid">';

        $conversationDraft = $this->task === 'Stab_schreiben';
        $conversationSelected =
            ($this->formdata['11_gesprnotiz'] ?? false) === true;
        $actualMediumEditable = (bool)$this->feld[1]
            || $this->task === 'LdF-Eingang'
            || $conversationDraft;
        $actualMediumEnabled = !$conversationDraft || $conversationSelected;
        $actualMediumRequired = $this->task === 'Stab_gesprnoti'
            || ($conversationDraft && $conversationSelected);
        $conversationMediumContext = $conversationDraft
            || $this->task === 'Stab_gesprnoti';
        $actualMediumOptions = $this->official_message_medium_options(
            '01_medium'
        );
        $selectedMediumLabel = '';
        foreach ($actualMediumOptions as $mediumOption) {
            if (hash_equals(
                (string)($this->formdata['01_medium'] ?? ''),
                $mediumOption['value']
            )) {
                $selectedMediumLabel = $mediumOption['label'];
                break;
            }
        }
        echo '<div class="estab-official-actual-medium" '
            . 'data-estab-conversation-medium '
            . 'data-estab-conversation-medium-controlled="'
            . ($conversationDraft ? 'true' : 'false') . '" '
            . 'data-estab-conversation-medium-active="'
            . ($actualMediumEnabled ? 'true' : 'false') . '">';
        $this->official_message_help(1);
        $this->official_message_radio_group(
            '01_medium',
            $actualMediumOptions,
            $actualMediumEditable,
            'Tatsächlich verwendetes Übermittlungsmittel',
            true,
            $actualMediumEnabled,
            $actualMediumRequired,
            $conversationMediumContext
                ? 'estab-conversation-medium-options'
                : '',
            $conversationMediumContext
                ? 'estab-conversation-medium-status'
                : ''
        );
        echo '</div>';

        echo '<section class="estab-official-ttb">'
            . '<div class="estab-official-cell-heading">'
            . 'Technisches<br>Betriebsbuch';
        $this->official_message_help(5);
        echo '</div><label for="f_04_nummer">Nr.</label>';
        echo '<input type="hidden" name="04_nummer" value="'
            . $this->safe_message_value('04_nummer') . '">'
            . '<span id="f_04_nummer" class="estab-official-readonly">'
            . estab_message_html($this->official_message_ttb_evidence_text())
            . '</span>';
        $this->official_message_radio_group(
            '04_richtung',
            [
                ['value' => 'E', 'label' => 'Eingang', 'id' => 'eingang'],
                ['value' => 'A', 'label' => 'Ausgang', 'id' => 'ausgang'],
            ],
            false,
            'Richtung im Technischen Betriebsbuch'
        );
        echo '<span class="estab-official-print-number">4</span></section>';

        echo '<div class="estab-official-direction-headings" aria-hidden="true">'
            . '<strong>Eingang</strong><strong>Ausgang</strong></div>';
        echo '<div class="estab-official-stamps">';
        $this->official_message_timestamp_block(
            'Aufnahmevermerk',
            1,
            2,
            '01_datum',
            '01_zeichen',
            (bool)$this->feld[1],
            'Datum und Uhrzeit des Eingangs',
            'Namenszeichen der Aufnahme'
        );
        $this->official_message_timestamp_block(
            'Annahmevermerk',
            2,
            3,
            '02_zeit',
            '02_zeichen',
            (bool)$this->feld[2],
            'Zeit der Annahme',
            'Namenszeichen der Annahme'
        );
        $this->official_message_timestamp_block(
            'Beförderungsvermerk',
            3,
            4,
            '03_datum',
            '03_zeichen',
            (bool)$this->feld[3],
            'Datum und Quittungszeit der Gegenstelle',
            'Namenszeichen der Beförderung'
        );
        echo '</div>';

        echo '<section class="estab-official-callsign">'
            . '<div class="estab-official-cell-heading">'
            . 'Rufname der Gegenstelle<br><span>Spruchkopf</span>';
        $this->official_message_help(6);
        echo '</div><div class="estab-official-field-value">';
        if ((bool)$this->feld[5]) {
            echo '<div class="estab-message-suggestion-control">';
            $this->official_message_text_input(
                '05_gegenstelle',
                true,
                128,
                'Rufname der Gegenstelle',
                $this->message_suggestion_input_attributes('05_gegenstelle')
            );
            $this->show_message_suggestions('05_gegenstelle');
            echo '</div>';
        } else {
            $this->official_message_text_input(
                '05_gegenstelle',
                false,
                128,
                'Rufname der Gegenstelle'
            );
        }
        echo '</div><span class="estab-official-print-number">5</span></section>';
        echo '</div></section>';

        echo '<div class="estab-official-section-rule" aria-hidden="true"></div>';
        echo '<section class="estab-official-zone estab-official-zone--content" '
            . 'data-estab-form-zone="nachricht">';

        echo '<aside class="estab-official-copy-distribution" '
            . 'data-estab-copy-distribution '
            . 'aria-label="Durchschriften und Verbleib">'
            . '<span data-estab-copy-sheet="1">Blatt 1 (blau) '
            . 'Sachgebiet/Fachber./Verbindungsstelle</span>'
            . '<span data-estab-copy-sheet="2">Blatt 2 (grün) '
            . 'Sachgebiet/Fachber./Verbindungsstelle</span>'
            . '<span data-estab-copy-sheet="3">Blatt 3 (rot) '
            . 'Sachgebiet 2 Lage</span>'
            . '<span data-estab-copy-sheet="4">Blatt 4 (gelb) '
            . 'Techn. Betriebsbuch</span>'
            . '</aside>'
            . '<span class="estab-official-punch-hole '
            . 'estab-official-punch-hole--upper" '
            . 'data-estab-punch-hole="upper" aria-hidden="true"></span>'
            . '<span class="estab-official-punch-hole '
            . 'estab-official-punch-hole--lower" '
            . 'data-estab-punch-hole="lower" aria-hidden="true"></span>';

        echo '<section class="estab-official-desired-medium">';
        $this->official_message_help(7);
        $this->official_message_radio_group(
            '06_befwegausw',
            $this->official_message_medium_options('06_befwegausw'),
            (bool)$this->feld[6] && $this->task !== 'LdF-Ausgang',
            'Gewünschtes Übermittlungsmittel',
            $this->task !== 'LdF-Ausgang'
        );
        echo '<span class="estab-official-print-number">6</span></section>';

        echo '<section class="estab-official-type-priority">'
            . '<div class="estab-official-type">';
        $this->official_message_help(8);
        $this->official_message_radio_group(
            '07_durchspruch',
            [
                ['value' => 'D', 'label' => 'DURCHSAGE', 'id' => 'durchsage'],
                ['value' => 'S', 'label' => 'Spruch', 'id' => 'spruch'],
            ],
            (bool)$this->feld[7],
            'Nachrichtenform'
        );
        echo '<span class="estab-official-print-number">7</span></div>'
            . '<div class="estab-official-priority">';
        $this->official_message_help(9);
        $this->official_message_priority();
        echo '<span class="estab-official-print-number">8</span></div></section>';

        echo '<section class="estab-official-address-block">'
            . '<div class="estab-official-address-label">'
            . '<div class="estab-official-cell-heading">Anschrift:';
        $this->official_message_help(10);
        echo '</div><span class="estab-official-print-number">9</span></div>'
            . '<div class="estab-official-address-value">';
        $this->official_message_textarea(
            '10_anschrift',
            (bool)$this->feld[10],
            'Anschrift',
            255
        );
        echo '</div><div class="estab-official-phone-label">'
            . '<div class="estab-official-cell-heading">Ruf Nr.';
        $this->official_message_help(11);
        echo '</div></div><div class="estab-official-phone-value">';
        $this->official_message_text_input(
            '11_rufnummer',
            (bool)$this->feld[10],
            128,
            'Rufnummer der Gegenstelle',
            ' inputmode="tel" autocomplete="tel"'
        );
        echo '</div><div class="estab-official-conversation">'
            . '<div class="estab-official-cell-heading">GESPRÄCHS-<br>NOTIZ';
        $this->official_message_help(12);
        echo '</div><div class="estab-official-conversation-control">';
        $this->official_message_checkbox(
            '11_gesprnotiz',
            (bool)$this->feld[11] && $this->task !== 'Stab_gesprnoti',
            'Gesprächsnotiz',
            $this->task !== 'Stab_korrigieren'
        );
        if ($conversationMediumContext) {
            echo '<span id="estab-conversation-medium-status" '
                . 'class="estab-official-conversation-medium-status" '
                . 'data-estab-conversation-medium-status aria-live="polite">'
                . ($actualMediumEnabled && $selectedMediumLabel !== ''
                    ? 'Ausgewählt: '
                        . estab_message_html($selectedMediumLabel) . '.'
                    : ($conversationSelected
                        ? 'Übermittlungsart oben auswählen.'
                        : 'Ankreuzen aktiviert oben Telefon, Funk, Telefax, '
                            . 'DFÜ oder Kurier/Melder.'))
                . '</span>';
        }
        echo '</div><span class="estab-official-print-number">10</span>'
            . '</div></section>';

        echo '<section class="estab-official-subject">'
            . '<div class="estab-official-cell-heading">Inhalt';
        $this->official_message_help(13);
        echo '</div><div class="estab-official-field-value">';
        $this->official_message_text_input(
            '12_betreff',
            (bool)$this->feld[12],
            255,
            'Betreff der Nachricht'
        );
        echo '</div><span class="estab-official-print-number">11</span></section>';

        echo '<section class="estab-official-message-text">';
        $this->official_message_help(14);
        $this->official_message_textarea(
            '12_inhalt',
            (bool)$this->feld[12],
            'Nachrichtentext'
        );
        echo '</section>';

        $senderAssignedByLead = in_array(
            $this->task,
            ['FM-Eingang', 'FM-Eingang_Anhang'],
            true
        );
        echo '<section class="estab-official-sender">'
            . '<div class="estab-official-sender-label">'
            . '<div class="estab-official-cell-heading">Absender:';
        $this->official_message_help(15);
        echo '</div></div><div class="estab-official-sender-value">';
        if ($senderAssignedByLead) {
            echo '<span id="f_13_abseinheit" '
                . 'class="estab-official-readonly" data-estab-readonly="true">'
                . 'Wird durch LdF aus dem Rufnamen ergänzt</span>';
        } elseif ((bool)$this->feld[13]) {
            echo '<div class="estab-message-suggestion-control">';
            $this->official_message_text_input(
                '13_abseinheit',
                true,
                128,
                'Absender',
                $this->message_suggestion_input_attributes('13_abseinheit')
            );
            $this->show_message_suggestions('13_abseinheit');
            echo '</div>';
        } else {
            $this->official_message_text_input(
                '13_abseinheit',
                false,
                128,
                'Absender'
            );
        }
        echo '</div><span class="estab-official-print-number">12</span></section>';

        echo '<section class="estab-official-composition">'
            . '<div class="estab-official-composition-label">'
            . '<div class="estab-official-cell-heading">Abfassungszeit:';
        $this->official_message_help(16);
        echo '</div></div><div class="estab-official-composition-value">';
        $this->official_message_text_input(
            '12_abfzeit',
            (bool)$this->feld[12],
            19,
            'Abfassungszeit',
            ' inputmode="numeric" autocomplete="off"'
        );
        echo '</div></section>';

        echo '<section class="estab-official-author">'
            . '<div class="estab-official-author-unit">'
            . '<span class="estab-official-print-number">13</span>'
            . '<span>Einheit/Einrichtung/Stelle</span></div>'
            . '<div class="estab-official-author-mark">'
            . '<span class="estab-official-print-number">14</span>';
        $this->official_message_help(17);
        if ((bool)$this->feld[14]) {
            $this->official_message_text_input(
                '14_zeichen',
                true,
                6,
                'Namenszeichen des Verfassers'
            );
        } else {
            echo '<input id="f_14_zeichen" type="hidden" '
                . 'name="14_zeichen" value="'
                . $this->safe_message_value('14_zeichen') . '">'
                . '<strong class="estab-official-readonly" '
                . 'data-estab-readonly="true">'
                . $this->safe_message_value('14_zeichen') . '</strong>';
        }
        echo '<span>Zeichen</span></div>'
            . '<div class="estab-official-author-function">';
        $this->official_message_text_input(
            '14_funktion',
            (bool)$this->feld[14],
            128,
            'Funktion des Verfassers'
        );
        echo '<span>Funktion</span></div></section>';
        echo '</section>';

        echo '<div class="estab-official-section-rule" aria-hidden="true"></div>';
        echo '<section class="estab-official-zone estab-official-zone--review" '
            . 'data-estab-form-zone="sichter">'
            . '<h2 class="estab-official-vertical-title">Sichter</h2>'
            . '<div class="estab-official-review-grid">';

        echo '<section class="estab-official-receipt">'
            . '<div class="estab-official-cell-heading">Quittung:';
        $this->official_message_help(18);
        echo '</div><div class="estab-official-receipt-value">';
        $reviewIdentityBound = $this->task === 'Stab_sichten';
        $immutableAdmin = in_array(
            $this->task,
            ['FM-Admin', 'SI-Admin'],
            true
        );
        if ($reviewIdentityBound) {
            echo '<span id="f_15_quitdatum" data-estab-readonly="true" '
                . 'data-estab-server-timestamp="submit" '
                . 'class="estab-official-readonly">'
                . $this->safe_message_value('15_quitdatum') . '</span>'
                . '<strong id="f_15_quitzeichen" '
                . 'data-estab-readonly="true" '
                . 'class="estab-official-readonly" '
                . 'aria-label="Sichterzeichen wird aus der Anmeldung übernommen">'
                . $this->safe_message_value('15_quitzeichen') . '</strong>';
        } else {
            $editableReceipt = (bool)$this->feld[15] && !$immutableAdmin;
            $this->official_message_text_input(
                '15_quitdatum',
                $editableReceipt,
                19,
                'Quittierungszeit',
                ' inputmode="numeric" autocomplete="off"',
                false
            );
            $this->official_message_text_input(
                '15_quitzeichen',
                $editableReceipt,
                6,
                'Namenszeichen der Quittung',
                '',
                false
            );
        }
        echo '</div><div class="estab-official-receipt-labels" '
            . 'aria-hidden="true"><span>Uhrzeit</span><span>Zeichen</span></div>'
            . '<span class="estab-official-print-number">15</span></section>';

        echo '<section class="estab-official-distribution">'
            . '<div class="estab-official-cell-heading">';
        $this->official_message_help(19);
        echo '</div>';
        $this->official_message_distribution();
        echo '<span class="estab-official-print-number">16</span></section>';

        echo '<section class="estab-official-notes">'
            . '<div class="estab-official-cell-heading">Vermerke:';
        $this->official_message_help(20);
        echo '</div>';
        $this->official_message_textarea(
            '17_vermerke',
            (bool)$this->feld[17],
            'Vermerke',
            0,
            false
        );
        echo '<span class="estab-official-print-number">17</span></section>';
        echo '</div></section></article></div>';
        $this->official_message_extra_distribution();

        $this->official_message_attachments();
        $this->official_message_actions('bottom');
        echo '</form></main>';
        $this->show_message_suggestion_script();
        $this->official_message_help_script();
        echo '</body></html>';
    }
}
