<?php
require_once __DIR__ . "/../app/csrf.php";
require_once __DIR__ . "/../app/message_repository.php";
require_once __DIR__ . "/../app/message_transport.php";
require_once __DIR__ . "/../app/read_authorization.php";
require_once __DIR__ . "/official_message_form.php";
if (defined ("debug") && debug) { echo "<b>!File:". __FILE__ ."  Line:". __LINE__ ."</b><big>4Fach Form</big><br>";  }
/*****************************************************************************\
   Datei: 4fachform.php

   benoetigte Dateien:

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/

include ("../4fcfg/config.inc.php");
include ("../4fcfg/dbcfg.inc.php");
include ("../4fcfg/e_cfg.inc.php");


/*****************************************************************************\
   Klasse: nachrichten4fach

   konstruktor : nachrichten4fach (Daten Ã¼bergabe,  fÃ¼r wen (task), Fehler im Formular)
   destruktor  :
   methoden    :

   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
class nachrichten4fach {

    use EstabOfficialMessageFormView;

	/*************************************************************************
	   Konstruktor der Klasse: nachrichten4fach

	**************************************************************************/
    function __construct ($formulardaten, $task, $errorselect){
      $this->nachrichten4fach ($formulardaten, $task, $errorselect);
    }

    function safe_message_value ($field) {
      return estab_message_html ($this->formdata [$field] ?? "");
    }

    function nachrichten4fach ($formulardaten, $task, $errorselect){
      $this->task = $task ;
      $this->hasUnsavedValidationData = $errorselect !== "";
      $formDefaults = array_fill_keys (array (
        "00_lfd", "01_datum", "01_medium", "01_zeichen", "02_zeichen",
        "02_zeit", "03_datum", "03_zeichen", "04_nummer", "04_richtung",
        "05_gegenstelle", "06_befweg", "06_befwegausw",
        "fernmeldeplan_eintrag_id", "transportweg_bestaetigt",
        "transport_rueckgabegrund",
        "incoming_transport_confirmed",
        "incoming_transport_original_medium",
        "incoming_transport_correction_reason",
        "07_durchspruch",
        "08_befhinwausw", "08_befhinweis", "09_vorrangstufe",
        "10_anschrift", "11_rufnummer", "11_gesprnotiz",
        "12_betreff", "12_abfzeit", "12_anhang",
        "12_inhalt", "13_abseinheit", "14_funktion", "14_zeichen",
        "15_quitdatum", "15_quitzeichen", "16_empf", "17_vermerke",
        "estab_route_error"
      ), "");
      $this->formdata = array_replace (
        $formDefaults,
        is_array ($formulardaten) ? $formulardaten : array ()
      );
      if (
        $this->task === "LdF-Eingang"
        && $this->formdata ["incoming_transport_original_medium"] === ""
      ) {
        $this->formdata ["incoming_transport_original_medium"] =
          $this->formdata ["01_medium"];
      }
      if (isset($this->formdata ["00_lfd"])) {$this->lfd = $this->formdata ["00_lfd"];}
      $errorDefaults = array_fill_keys (array (
        "01_medium", "01_datum", "01_zeichen", "02_zeit", "02_zeichen",
        "03_datum", "03_zeit", "03_zeichen", "05_gegenstelle",
        "06_befweg", "06_befwegausw", "07_durchspruch",
        "incoming_transport_confirmed",
        "08_befhinweis", "08_befhinwausw", "10_anschrift",
        "11_rufnummer", "12_betreff", "12_inhalt", "12_abfzeit",
        "13_abseinheit", "14_zeichen",
        "14_funktion", "15_quitdatum", "15_quitzeichen", "17_vermerke"
      ), true);
      $this->errorselect = array_replace (
        $errorDefaults,
        is_array ($errorselect) ? $errorselect : array ()
      );
      foreach (array ("01_datum", "02_zeit", "03_datum", "12_abfzeit", "15_quitdatum") as $dateField) {
        if (!isset ($this->formdata [$dateField]) || estab_datetime_is_unset ($this->formdata [$dateField])) {
          $this->formdata [$dateField] = "";
        } elseif (
          is_string ($this->formdata [$dateField])
          && preg_match (
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',
            $this->formdata [$dateField]
          ) === 1
        ) {
          // Datenbankwerte können aus dem normalen Abruf sowie aus Fehler-
          // und Anhang-Rückwegen stammen. Am gemeinsamen Render-Rand werden
          // sie genau einmal in die taktische Schreibweise umgewandelt.
          $this->formdata [$dateField] =
            konv_datetime_taktime ($this->formdata [$dateField]);
        }
      }
      $editableTimestampField = array (
        "FM-Eingang" => "01_datum",
        "FM-Eingang_Anhang" => "01_datum",
        "LdF-Eingang" => "02_zeit",
        "LdF-Ausgang" => "02_zeit",
        "FM-Ausgang" => "03_datum"
      ) [$this->task] ?? "";
      if (
        $editableTimestampField !== ""
        && $this->formdata [$editableTimestampField] === ""
      ) {
        // Der sichtbare Standard entspricht dem bisherigen serverseitigen
        // Leerwert-Fallback, bleibt im Formular aber frei korrigierbar.
        $this->formdata [$editableTimestampField] = date ("Hi");
      }
      if ((!isset($this->formdata ["17_vermerke"]))  or ($this->formdata ["17_vermerke"] == "0000-00-00 00:00:00")) { $this->formdata ["17_vermerke"] = ""; }
      $conversationNote = $this->formdata ["11_gesprnotiz"] ?? "";
      if ( ($conversationNote == "t") OR
           ($conversationNote == "1") OR
           ($conversationNote == "on") ) {

        $this->formdata   ["11_gesprnotiz"] = true;
      } else {
        $this->formdata ["11_gesprnotiz"] = false;
      }

      if (debug){
        echo "<br><big>4fach data 087="; var_dump ($this->formdata); echo "</big><br>";
      }
      if ($this->task === "LdF-Ausgang") {
        $this->activeTelecomRoutes = $this->load_active_telecom_routes ();
      }
      $this->load_message_suggestions ();
      $this->plot_form () ;
    }

    var $task;        // text , Fuer welche Funktion ist der Vordruck
    var $formdata ;   // array, Formulardaten
    var $lfd ;        // integer, laufende Nummer der Nachricht
    var $errorselect; // array, Felder die falsch eingegeben wurden.
    var $hasUnsavedValidationData = false;
    var $activeTelecomRoutes = array ();
    var $messageSuggestionField = "";
    var $messageSuggestions = array ();
    var $messageSuggestionMetadata = array ();
    var $messageMappingContext = "";

    function load_active_telecom_routes () {
      global $conf_4f_db;
      $connection = estab_message_connect ($conf_4f_db);
      try {
        $incident = estab_incident_active ($connection);
        if (!is_array ($incident)) {
          return array ();
        }
        $plans = estab_dv_telecom_plans (
          $connection,
          (int) $incident ["active_einsatz_id"]
        );
        $routes = array ();
        $now = time ();
        foreach ($plans as $plan) {
          $validFrom = strtotime ((string) ($plan ["gueltig_ab"] ?? ""));
          $validUntil = ($plan ["gueltig_bis"] ?? null) === null
            ? null
            : strtotime ((string) $plan ["gueltig_bis"]);
          if (
            ($plan ["status"] ?? "") !== "AKTIV"
            || $validFrom === false
            || $validFrom > $now
            || ($validUntil !== null && $validUntil < $now)
          ) {
            continue;
          }
          foreach (($plan ["eintraege"] ?? array ()) as $entry) {
            $entry ["plan_version"] = (int) $plan ["version"];
            $routes [] = $entry;
          }
        }
        return $routes;
      } finally {
        estab_auth_close ($connection);
      }
    }

    function load_message_suggestions () {
      global $conf_4f_db, $conf_4f_tbl;
      $field = match ($this->task) {
        "FM-Eingang", "FM-Eingang_Anhang", "LdF-Ausgang" =>
          "05_gegenstelle",
        "LdF-Eingang" => "13_abseinheit",
        default => "",
      };
      if ($field === "") {
        return;
      }
      $this->messageSuggestionField = $field;
      $connection = null;
      try {
        $identity = estab_read_session_identity ($_SESSION);
        if (!is_array ($identity)) {
          return;
        }
        $connection = estab_message_connect ($conf_4f_db);
        $history = estab_read_message_suggestions (
          $connection,
          (string) $conf_4f_tbl ["nachrichten"],
          $identity,
          $field
        );
        $mapped = array ();
        $mappingDirection = match ($this->task) {
          "LdF-Eingang" => "E",
          "LdF-Ausgang" => "A",
          default => "",
        };
        if ($mappingDirection !== "" && (int) $this->lfd > 0) {
          try {
            $mapped = estab_read_ldf_mapping_suggestions (
              $connection,
              (string) $conf_4f_tbl ["nachrichten"],
              $identity,
              (int) $this->lfd,
              $mappingDirection
            );
          } catch (Throwable $exception) {
            // The generic active-incident history remains usable if the
            // optional pair/S6 lookup is temporarily unavailable.
            error_log ("eStab LdF mappings are temporarily unavailable");
          }
        }
        $seen = array ();
        foreach ($mapped as $mapping) {
          $value = (string) ($mapping ["value"] ?? "");
          if ($value === "") {
            continue;
          }
          $key = function_exists ("mb_strtolower")
            ? mb_strtolower ($value, "UTF-8")
            : strtolower ($value);
          if (isset ($seen [$key])) {
            continue;
          }
          $seen [$key] = true;
          $this->messageSuggestions [] = $value;
          $this->messageSuggestionMetadata [$value] = array (
            "source" => (string) ($mapping ["source"] ?? ""),
            "match" => (string) ($mapping ["match"] ?? ""),
            "matched_context" =>
              (string) ($mapping ["matched_context"] ?? ""),
          );
          if ($this->messageMappingContext === "") {
            $this->messageMappingContext =
              (string) ($mapping ["context"] ?? "");
          }
        }
        foreach ($history as $value) {
          $key = function_exists ("mb_strtolower")
            ? mb_strtolower ($value, "UTF-8")
            : strtolower ($value);
          if (isset ($seen [$key])) {
            continue;
          }
          $seen [$key] = true;
          $this->messageSuggestions [] = $value;
          if (count ($this->messageSuggestions) >= 30) {
            break;
          }
        }
      } catch (Throwable $exception) {
        // Suggestions are optional assistance. The guarded form remains
        // usable if this read-only lookup is temporarily unavailable.
        error_log ("eStab message suggestions are temporarily unavailable");
        $this->messageSuggestions = array ();
      } finally {
        if ($connection instanceof mysqli) {
          estab_auth_close ($connection);
        }
      }
    }

    function message_suggestion_definition ($field) {
      if ($this->messageSuggestionField !== $field) {
        return null;
      }
      return match ($field) {
        "05_gegenstelle" => array (
          "id" => "estab-message-callsign-suggestions",
          "kind" => "callsign",
        ),
        "13_abseinheit" => array (
          "id" => "estab-message-sender-suggestions",
          "kind" => "sender",
        ),
        default => null,
      };
    }

    function message_suggestion_input_attributes ($field) {
      $definition = $this->message_suggestion_definition ($field);
      if (!is_array ($definition)) {
        return "";
      }
      $id = $definition ["id"];
      return " list=\"".$id."-native\" autocomplete=\"off\"".
        " data-estab-incident-suggestions=\"".$definition ["kind"]."\"".
        " data-estab-suggestion-listbox=\"".$id."\"".
        " role=\"combobox\" aria-autocomplete=\"list\"".
        " aria-haspopup=\"listbox\" aria-expanded=\"false\"".
        " aria-controls=\"".$id."\"".
        " aria-describedby=\"".$id."-hint\"";
    }

    function message_suggestion_presentation ($suggestion) {
      $metadata = $this->messageSuggestionMetadata [$suggestion] ?? null;
      if (!is_array ($metadata)) {
        return array (
          "source" => "",
          "quality" => "",
          "label" => "",
          "matched_context" => "",
        );
      }
      $source = (string) ($metadata ["source"] ?? "");
      $match = (string) ($metadata ["match"] ?? "");
      $sourceLabel = match ($source) {
        "message" => "Bestätigtes Nachrichtenpaar",
        "plan" => "Aktiver S6-Fernmeldeplan",
        default => "",
      };
      $matchLabel = match ($match) {
        "exact" => "Exakt",
        "related" => "Ähnlich",
        default => "",
      };
      if ($sourceLabel === "" || $matchLabel === "") {
        return array (
          "source" => "",
          "quality" => "",
          "label" => "",
          "matched_context" => "",
        );
      }
      $matchedContext = $match === "related"
        ? (string) ($metadata ["matched_context"] ?? "")
        : "";
      return array (
        "source" => $source,
        "quality" => $match,
        "label" => $match === "exact"
          ? $sourceLabel
          : $matchLabel." · ".$sourceLabel,
        "matched_context" => $matchedContext,
      );
    }

    function show_message_suggestions ($field) {
      $definition = $this->message_suggestion_definition ($field);
      if (!is_array ($definition)) {
        return;
      }
      $id = $definition ["id"];
      echo "<datalist id=\"".$id."-native\" ".
        "data-estab-incident-suggestion-list=\"".$definition ["kind"]."\">\n";
      foreach ($this->messageSuggestions as $suggestion) {
        $presentation =
          $this->message_suggestion_presentation ($suggestion);
        $sourceLabel = (string) $presentation ["label"];
        $matchedContext = (string) $presentation ["matched_context"];
        $nativeLabel = $sourceLabel;
        if ($nativeLabel !== "" && $matchedContext !== "") {
          $nativeLabel .= " · Bezug: ".$matchedContext;
        }
        echo "<option value=\"".estab_auth_html ($suggestion)."\"".
          ($nativeLabel === ""
            ? ""
            : " label=\"".estab_auth_html ($nativeLabel)."\"").
          "></option>\n";
      }
      echo "</datalist>\n";
      echo "<div id=\"".$id."\" class=\"estab-message-suggestion-list\" ".
        "role=\"listbox\" aria-label=\"Vorschläge aus dem aktiven Einsatz\" ".
        "hidden>\n";
      foreach ($this->messageSuggestions as $index => $suggestion) {
        $presentation =
          $this->message_suggestion_presentation ($suggestion);
        $source = (string) $presentation ["source"];
        $quality = (string) $presentation ["quality"];
        $sourceLabel = (string) $presentation ["label"];
        $matchedContext = (string) $presentation ["matched_context"];
        echo "<div id=\"".$id."-option-".$index."\" ".
          "class=\"estab-message-suggestion-option".
          ($sourceLabel === "" ? "" : " estab-message-mapping-option").
          "\" role=\"option\" tabindex=\"-1\" ".
          "data-estab-suggestion-value=\"".
          estab_auth_html ($suggestion)."\"".
          ($sourceLabel === ""
            ? ""
            : " data-estab-mapping-match=\"".
              estab_auth_html ($source)."\"".
              " data-estab-mapping-quality=\"".
              estab_auth_html ($quality)."\"").
          "><span class=\"estab-message-suggestion-value\">".
          estab_auth_html ($suggestion)."</span>".
          ($sourceLabel === ""
            ? ""
            : "<small class=\"estab-message-suggestion-source\">".
              estab_auth_html ($sourceLabel).
              ($matchedContext === ""
                ? ""
                : "<br><span ".
                  "class=\"estab-message-suggestion-match-context\">".
                  "Bezug: „".estab_auth_html ($matchedContext)."“</span>").
              "</small>").
          "</div>\n";
      }
      echo "</div>\n";
      echo "<small id=\"".$id."-hint\" ".
        "class=\"estab-message-suggestion-hint\">";
      if ($this->messageMappingContext !== "") {
        echo "Passende Zuordnungen zu „".
          estab_auth_html ($this->messageMappingContext).
          "“ stehen zuerst: abgeschlossene Nachrichten vor dem aktiven ".
          "S6-Fernmeldeplan. ";
      } else {
        echo "Bisherige Werte aus dem aktiven Einsatz. ";
      }
      echo "Freie Eingabe bleibt möglich.</small>\n";
    }

    function show_message_suggestion_script () {
      if ($this->messageSuggestionField === "") {
        return;
      }
      echo <<<'HTML'
<script data-estab-message-suggestion-picker>
(function () {
  "use strict";
  var inputs = document.querySelectorAll(
    "input[data-estab-incident-suggestions]"
  );

  function listFor(input) {
    var id = input.getAttribute("data-estab-suggestion-listbox");
    return id ? document.getElementById(id) : null;
  }

  function optionsFor(list) {
    return Array.prototype.slice.call(
      list.querySelectorAll('[role="option"]')
    );
  }

  function setActive(input, options, active) {
    for (var index = 0; index < options.length; index++) {
      var selected = options[index] === active;
      options[index].classList.toggle(
        "estab-message-suggestion-option-active",
        selected
      );
      options[index].setAttribute("aria-selected", selected ? "true" : "false");
    }
    if (active) {
      input.setAttribute("aria-activedescendant", active.id);
      active.scrollIntoView({block: "nearest"});
    } else {
      input.removeAttribute("aria-activedescendant");
    }
  }

  function closeList(input, list) {
    list.hidden = true;
    input.setAttribute("aria-expanded", "false");
    setActive(input, optionsFor(list), null);
  }

  function matchingOptions(input, list) {
    var query = input.value.trim().toLocaleLowerCase();
    var options = optionsFor(list);
    var visible = [];
    for (var index = 0; index < options.length; index++) {
      var value = options[index].getAttribute(
        "data-estab-suggestion-value"
      ) || options[index].textContent;
      var label = value.toLocaleLowerCase();
      var matches = query === "" || label.indexOf(query) !== -1;
      options[index].hidden = !matches;
      if (matches) {
        visible.push(options[index]);
      }
    }
    setActive(input, options, null);
    return visible;
  }

  function openList(input, list) {
    var visible = matchingOptions(input, list);
    list.hidden = visible.length === 0;
    input.setAttribute(
      "aria-expanded",
      visible.length === 0 ? "false" : "true"
    );
    return visible;
  }

  function choose(input, list, option) {
    input.value = option.getAttribute("data-estab-suggestion-value")
      || option.textContent;
    closeList(input, list);
    input.focus();
    input.dispatchEvent(new Event("change", {bubbles: true}));
  }

  for (var inputIndex = 0; inputIndex < inputs.length; inputIndex++) {
    (function (input) {
      var list = listFor(input);
      if (!list) {
        return;
      }

      // With JavaScript disabled, the native datalist remains the fallback.
      // The custom listbox is used otherwise so focus behavior is identical
      // even in browsers without HTMLInputElement.showPicker().
      input.removeAttribute("list");

      input.addEventListener("focus", function () {
        openList(input, list);
      });
      input.addEventListener("input", function () {
        openList(input, list);
      });
      input.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
          closeList(input, list);
          return;
        }
        var activeId = input.getAttribute("aria-activedescendant");
        var active = activeId ? document.getElementById(activeId) : null;
        if (event.key === "Enter" && active) {
          event.preventDefault();
          choose(input, list, active);
          return;
        }
        if (event.key !== "ArrowDown" && event.key !== "ArrowUp") {
          return;
        }
        event.preventDefault();
        var visible = list.hidden
          ? openList(input, list)
          : matchingOptions(input, list);
        if (visible.length === 0) {
          return;
        }
        var current = visible.indexOf(active);
        var next = event.key === "ArrowDown"
          ? (current + 1) % visible.length
          : (current <= 0 ? visible.length - 1 : current - 1);
        setActive(input, optionsFor(list), visible[next]);
      });
      input.addEventListener("blur", function () {
        window.setTimeout(function () {
          if (!list.contains(document.activeElement)) {
            closeList(input, list);
          }
        }, 0);
      });
      list.addEventListener("mousedown", function (event) {
        var option = event.target.closest('[role="option"]');
        if (option && list.contains(option)) {
          event.preventDefault();
        }
      });
      list.addEventListener("click", function (event) {
        var option = event.target.closest('[role="option"]');
        if (option && list.contains(option) && !option.hidden) {
          choose(input, list, option);
        }
      });
    })(inputs[inputIndex]);
  }

  document.addEventListener("mousedown", function (event) {
    for (var index = 0; index < inputs.length; index++) {
      var input = inputs[index];
      var list = listFor(input);
      if (
        list
        && event.target !== input
        && !list.contains(event.target)
      ) {
        closeList(input, list);
      }
    }
  });
})();
</script>
HTML;
      echo "\n";
    }

  // aktive und Inaktive Darstellungsfarben

  var $fktmsgbgcolor ;  // Hintergrundfarbe
  var $bg_color_fm_a  ; // rosa Fernmelder aktiv
  var $bg_color_fmp_a ; // hell grï¿½n Fernmelderpflichtfeld  aktiv
  var $bg_color_nw_a  ;  // orange
  var $bg_color_tx_a  ; // hell blau
  var $bg_color_si_a  ; // hell violett
  var $bg_color_inaktv ;  // weiss
  var $bg_color_aktv  ;  // weiss
  var $rbl_bg_color ;  // weiss
  var $bg_color_aktv_must ; // rot

  var $feldbg ;
  var $redcopy2;

  /****************************************************************************\
    Hintergrundfarben der Felder aktiv und inaktiv
  \****************************************************************************/
  function feldbgcolor (){
    if ($this->task == "FM-Eingang") {
      $this->feldbg [ 1]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [10]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [12]["a"] = $this->bg_color_fmp_a;
      $this->feldbg [13]["a"] = $this->bg_color_fmp_a;
    } else {
       $this->feldbg [ 1]["a"] = $this->bg_color_tx_a;
       $this->feldbg [10]["a"] = $this->bg_color_tx_a;
       $this->feldbg [12]["a"] = $this->bg_color_tx_a;
       $this->feldbg [13]["a"] = $this->bg_color_tx_a;
    }

    $this->feldbg [ 1]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 2]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 2]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 3]["a"] = $this->bg_color_fmp_a; //mpr corrected script for Ausgang
    $this->feldbg [ 3]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 4]["a"] = $this->bg_color_fm_a;
    $this->feldbg [ 4]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 5]["a"] = $this->bg_color_fmp_a; //mpr corrected script for Ausgang
    $this->feldbg [ 5]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 6]["a"] = $this->bg_color_fmp_a; //mpr corrected script for Ausgang
    $this->feldbg [ 6]["i"] = $this->bg_color_inaktv;

    $this->feldbg [ 7]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 7]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 8]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 8]["i"] = $this->bg_color_inaktv;
    $this->feldbg [ 9]["a"] = $this->bg_color_tx_a;
    $this->feldbg [ 9]["i"] = $this->bg_color_inaktv;

    $this->feldbg [10]["i"] = $this->bg_color_inaktv;
    $this->feldbg [11]["a"] = $this->bg_color_tx_a;
    $this->feldbg [11]["i"] = $this->bg_color_inaktv;

    $this->feldbg [12]["i"] = $this->bg_color_inaktv;

    $this->feldbg [13]["i"] = $this->bg_color_inaktv;
    $this->feldbg [14]["a"] = $this->bg_color_tx_a;
    $this->feldbg [14]["i"] = $this->bg_color_inaktv;

    $this->feldbg [15]["a"] = $this->bg_color_si_a;
    $this->feldbg [15]["i"] = $this->bg_color_inaktv;
    $this->feldbg [16]["a"] = $this->bg_color_si_a;
    $this->feldbg [16]["i"] = $this->bg_color_inaktv;
    $this->feldbg [17]["a"] = $this->bg_color_si_a;
    $this->feldbg [17]["i"] = $this->bg_color_inaktv;
  }

  // Zuordnung der notwendigen Farben
  var $bg;
  var $feld ;

/*****************************************************************************\
   Funktion    : get_access_by_task
   Beschreibung: 
     

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function get_access_by_task (){
    // Alle Felder auf inaktiv setzen
    for ( $i = 1; $i <= 17; $i++ ){
      $this->bg [$i] = $this->feldbg [$i]["i"] ;
      $this->feld [$i] = false;
    }

    switch ($this->task) {
      // Annahme einer Meldung durch Fernmelder
      case "FM-Eingang" :
      case "FM-Eingang_Anhang" :

        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;
        $this->bg [5] = $this->feldbg [5]["a"] ;
        $this->feld [5] = true;
        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        $this->bg   [11] = $this->feldbg [11]["i"] ;
        $this->feld [11] = false;
        $this->bg   [13] = $this->feldbg [13]["i"] ;
        $this->feld [13] = false;

      break;
      case "LdF-Eingang":
        // LdF confirms or corrects only the incoming transport medium. The
        // A/W receipt time and receipt mark in the same visual block stay
        // immutable and are therefore not enabled through field bit 1.
        $this->bg [1] = $this->feldbg [1]["a"];
        $this->bg [2] = $this->feldbg [2]["a"];
        $this->feld [2] = true;
        $this->bg [13] = $this->feldbg [13]["a"];
        $this->feld [13] = true;
      break;
      case "LdF-Ausgang":
        $this->bg [2] = $this->feldbg [2]["a"];
        $this->feld [2] = true;
        $this->bg [5] = $this->feldbg [5]["a"];
        $this->feld [5] = true;
        $this->bg [6] = $this->feldbg [6]["a"];
        $this->feld [6] = true;
      break;
      // Weitergabe einer Meldung durch den Fernmelder
      case "FM-Ausgang" :
        $this->bg [3] = $this->feldbg [3]["a"] ;
        $this->feld [3] = true;
      break;


      case "Stab_schreiben" :
      case "Stab_korrigieren" :
        for ($i=7;$i<=13;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        // Verfasserzeichen und ausgeübte Funktion come from the login.
        $this->bg [14] = $this->feldbg [14]["a"];
        $this->feld [14] = false;
        // The local organisation is derived from server configuration.
        $this->feld [13] = false;
        if ($this->task === "Stab_korrigieren") {
          // A formally returned outgoing message remains an outgoing message.
          // Its author may correct content, but may not convert the object into
          // an independently completed conversation note.
          $this->bg [11] = $this->feldbg [11]["i"];
          $this->feld [11] = false;
        }
      break;

      case "Stab_lesen" :
        for ($i=1;$i<=17;$i++){
          $this->bg [$i] = $this->formbgcolor ;
          $this->feld [$i] = false;
        }

      break;

      case "Stab_sichten" :
        $this->bg [15] = $this->feldbg [15]["a"];
        $this->feld [15] = true;
        $this->bg [17] = $this->feldbg [17]["a"];
        $this->feld [17] = true;
        if (($this->formdata ["04_richtung"] ?? "") === "E") {
          // Incoming sighting includes the content-based distribution.
          $this->bg [16] = $this->feldbg [16]["a"];
          $this->feld [16] = true;
        }
      break;
      case "Stab_gesprnoti":
        $this->bg [1] = $this->feldbg [1]["a"] ;
        $this->feld [1] = true;

        for ($i=7;$i<=14;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        for ($i=15;$i<=17;$i++){
          $this->bg [$i] = $this->feldbg [$i]["a"] ;
          $this->feld [$i] = true;
        }
        // Author, organisation and review marks are authoritative metadata.
        // They are shown for orientation but are never browser-editable.
        $this->feld [13] = false;
        $this->feld [14] = false;
        $this->bg [15] = $this->feldbg [15]["i"];
        $this->feld [15] = false;
      break;

      case "FM-Admin" :
      case "SI-Admin" :
        // Completed records are evidence. Administration is a read-only view;
        // corrections require a new, explicitly linked record.
      break;

      default :
        for ($i=1;$i<=17;$i++){
          $this->feld [$i] = false;
        }
    } // switch $rolle
  }

  var $empfarray ;

/*****************************************************************************\
   Funktion    : ziele ()
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function ziele (){
  include ("../4fcfg/fkt_rolle.inc.php");

    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        $this->empfarray [$i][$j]["checked"] = false;
        $this->empfarray [$i][$j]["cpycol"]  = "";
        $this->empfarray [$i][$j]["typ"]     = $empf_matrix [$i][$j]["typ"];
        $this->empfarray [$i][$j]["fkt"]     = $empf_matrix [$i][$j]["fkt"];
        $this->empfarray [$i][$j]["rolle"]   = $empf_matrix [$i][$j]["rolle"];
        $this->empfarray [$i][$j]["auto"]    = $empf_matrix [$i][$j]["auto"];
      }
    }

    $empf_text = isset($this->formdata["16_empf"])
        && is_string($this->formdata["16_empf"])
        ? $this->formdata["16_empf"]
        : ""; // Zeile mit den Empfaengern aus der DB
    $empf_array = estab_recipient_copy_map ($empf_text);
    $sessionFunction = (string) ($_SESSION ['vStab_funktion'] ?? "");
    if (isset ($empf_array [$sessionFunction])) {
      $sessionColours = estab_recipient_copy_colours (
        $empf_array [$sessionFunction]
      );
      $this->fktmsgbgcolor = $sessionColours [0] ?? "";
    }
    $sonstcount = 2;
    for ($i=1; $i <= 5 ; $i++){
      for ($j=1; $j <= 4 ; $j++){
        $matrixFunction = (string) $empf_matrix [$i][$j]["fkt"];
        if ($matrixFunction !== "" && isset ($empf_array [$matrixFunction])) {
          $this->empfarray [$i][$j]["checked"] = true;
          $this->empfarray [$i][$j]["cpycol"] =
            $empf_array [$matrixFunction];
        }
      }
    }
  $this->redcopy2 = $redcopy2;

  }


/*****************************************************************************\
   Funktion    :
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
    // Listet unter Inhalt eventuelle Anhangsdateien als href auf
  function list_anhang (){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
      // in 12_anhang stehen die Anhangdateien mit ";" getrennt.
    echo "<br>";
    $anhaenge = preg_split("/;/", $this->formdata ["12_anhang"]);
    foreach ($anhaenge as $anhang){
      if ($anhang != "") {
        try {
          $downloadUrl = estab_file_download_url ($conf_4f ["download_uri"], "attachment", $anhang);
        } catch (InvalidArgumentException) {
          continue;
        }
        echo "<a style=\"font-size:18px; font-weight:900;\" href=\"".
             estab_auth_html ($downloadUrl)."\" target=\"_blank\" rel=\"noopener\">".
             estab_auth_html ($anhang)."</a><br>";
      }
    }
  } // list_anhang ()

/*****************************************************************************\
   Funktion    : show_menue_buttons ()
   Beschreibung:

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function show_menue_buttons ($umfang, $ordnum){
    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");

//    echo "<fieldset>";
//    echo "<legend>Aktion:</legend>\n";
//    echo "<TABLE BORDER=\"1\" CELLSPACING=\"2\" CELLPEDDING=\"0\" bgcolor=$color_data_table >\n";
    echo "<TABLE>\n";
    echo "<TBODY>\n";
    echo "<TR>\n";
    echo "<TD>\n";
    if ($umfang == 2){
      echo "<TABLE BORDER=\"0\" CELLSPACING=\"1\" CELLPEDDING=\"0\">\n";
      echo "<input type=\"hidden\" name=\"kate_todo\" value=\"speichern\">\n";
	  if (isset($this->formdata["00_lfd"])){
	    $lfd_value = $this->formdata["00_lfd"];
	  }else{
	    $lfd_value = "";}	
	  
      echo "<input type=\"hidden\" name=\"msglfd\" value=\"".estab_message_html ($lfd_value)."\">\n";
      echo "<TR>\n";
      echo "<TBODY>\n";
      echo "<TD>\n";
        // Druckersymbol
      echo "<a href=\"javascript:window.print()\">
            <img src=\"".$conf_design_path."/print.gif\" alt=\"Drucken\" width=\"32\"height=\"32\" border=\"0\" title=\"Drucken\"></a>\n";
      echo "</TD>\n";

      if ( $this->task == "Stab_lesen"){   // Stab lesen
          // MASTER KATEGORIE
        echo "<TD>";
        $katego_master = new  kategorien ("master");
        $katearr_master = $katego_master->db_get_kategobymsg ($this->formdata["00_lfd"]);
        $masterManagerUrl = "katgoedt.php?".http_build_query (array (
          "dbtyp" => "master",
          "msgno" => (string) $this->formdata ["00_lfd"],
        ), "", "&", PHP_QUERY_RFC3986);
        echo"<a ";
          // Ist die Funktion berechtigt globale Kategorien zu aendern?
        $berechtigt = ($_SESSION ["vStab_funktion"] == $redcopy2) OR
                      ($_SESSION ["vStab_funktion"] == "Si");
        if ($berechtigt) {
          echo "href=\"".estab_message_html ($masterManagerUrl)."\"";
        }
        echo ">
            <img src=\"".estab_message_html ($conf_design_path."/folder_global.gif")."\"
                 alt=\"globale Ordner verwalten (falls berechtigt)\"
                 width=\"32\"
                 height=\"32\"
                 border=\"0\"
                 title=\"globale Ordner verwalten (falls berechtigt)\"></a>";
        echo "</TD>";

          // Schreibe die augenblickliche Masterkategorie hin
        if ( ($katearr_master["kategorie"] ?? "") != ""){
          echo "<TD>";
          $color = "red";
          $masterIcon = "./createbutton.php?".http_build_query (array (
            "icontext" => $katearr_master ["kategorie"],
            "color" => $color,
          ), "", "&", PHP_QUERY_RFC3986);
          echo"<a><img src=\"".estab_message_html ($masterIcon)."\" alt=\"".
            estab_auth_html ($katearr_master["beschreibung"] ?? "")."\"></a>";
          echo "</TD>";
        }

        echo "<TD>";

        if ($berechtigt) {
          $katego_master->pulldown_kategorien ($katearr_master["lfd"] ?? "", true, $ordnum);
        }
        echo "</TD>\n";

          // FUNKTIONS KATEGORIE
        echo "<TD>\n";
        $katego_fkt = new  kategorien ("fkt");
        $katearr_fkt = $katego_fkt->db_get_kategobymsg ($this->formdata["00_lfd"]);
        $functionManagerUrl = "katgoedt.php?".http_build_query (array (
          "dbtyp" => "fkt",
          "msgno" => (string) $this->formdata ["00_lfd"],
        ), "", "&", PHP_QUERY_RFC3986);
        echo"<a href=\"".estab_message_html ($functionManagerUrl)."\">
            <img src=\"".estab_message_html ($conf_design_path."/folder_user.gif")."\"
                 alt=\"Funktionsordner verwalten\"
                 width=\"32\"
                 height=\"32\"
                 border=\"0\"
                 title=\"funktionsordner verwalten\"></a>\n";
        echo "</TD>\n";
        echo "<TD>\n";
        echo "</TD>";
        echo "<TD>";
        if (($katearr_fkt["kategorie"] ?? "") != "" ){
          $color = "blue";
          $functionIcon = "./createbutton.php?".http_build_query (array (
            "icontext" => $katearr_fkt ["kategorie"],
            "color" => $color,
          ), "", "&", PHP_QUERY_RFC3986);
          echo"<a><img src=\"".estab_message_html ($functionIcon)."\" alt=\"".
            estab_auth_html ($katearr_fkt["beschreibung"] ?? "")."\"></a>";
        }
        echo "</TD>";
        echo "<TD>";
        $katego_fkt->pulldown_kategorien ($katearr_fkt["lfd"] ?? "", true, $ordnum);
        echo "</TD>\n";

         // BENUTZER KATEGORIE
        echo "<TD>\n";
        $katego_user = new  kategorien ("user");
        $katearr_user = $katego_user->db_get_kategobymsg ($this->formdata["00_lfd"]);
        $userManagerUrl = "katgoedt.php?".http_build_query (array (
          "dbtyp" => "user",
          "msgno" => (string) $this->formdata ["00_lfd"],
        ), "", "&", PHP_QUERY_RFC3986);
        echo"<a href=\"".estab_message_html ($userManagerUrl)."\">
            <img src=\"".estab_message_html ($conf_design_path."/folder_local.gif")."\"
                 alt=\"persönliche Ordner verwalten\"
                 width=\"32\"
                 height=\"32\"
                 border=\"0\"
                 title=\"persönliche Ordner verwalten\"></a>\n";
        echo "</TD>\n";
        echo "<TD>\n";
        echo "</TD>";
        echo "<TD>";
        if (($katearr_user["kategorie"] ?? "") != "" ){
          $color = "green";
          $userIcon = "./createbutton.php?".http_build_query (array (
            "icontext" => $katearr_user ["kategorie"],
            "color" => $color,
          ), "", "&", PHP_QUERY_RFC3986);
          echo"<a><img src=\"".estab_message_html ($userIcon)."\" alt=\"".
            estab_auth_html ($katearr_user["beschreibung"] ?? "")."\"></a>";
        }
        echo "</TD>";
        echo "<TD>";
        $katego_user->pulldown_kategorien ($katearr_user["lfd"] ?? "", true, $ordnum);
        echo "</TD>";
        echo "<TD><button type=\"submit\" name=\"category_action\" value=\"assign\" ".
          "formaction=\"katgoedt.php\">Kategorien zuordnen</button>";
        echo "</TD>\n";
      }
      echo "</TR>\n";
      echo "</TBODY>\n";
      echo "</TABLE>\n";
    }
    echo "</TD>";

    echo "<TD>";
//    echo "<TABLE BORDER=\"1\" CELLSPACING=\"1\" CELLPEDDING=\"1\">\n";
        echo "<TABLE>\n";
    echo "<TBODY>\n";
    echo "<TR>\n";
          /*
                                          04Richtung      Antwort Weiterleitung

          FM      FM-Eingang                      -       -         -
          FM      FM-Ausgang                      A       X         -

          Si      Stab_sichten                    E       -         -
          Si      Stab_sichten                    A       -         -
          Si      SI-Admin                        E       -         -
          Si      SI-Admin                        A       -         -

          Stab    Stab_lesen                      E       X         X
          Stab    Stab_lesen                      A       -         X

          Stab    Stab_schreiben                  -       -         -
                                                          2         2
          */

      switch ($this->task){
      case "Stab_lesen":
          echo "<td>\n";
          echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
          echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
          echo "<input type=\"image\" name=\"gelesen\" src=\"button.php?type=menue&m_text=gelesen/OK&m_fs=10&m_form=rund&bg=lightblue\" alt=\"gelesen\">\n";
          echo "</td>";

          if ($this->formdata["04_richtung"]=="E"){
            echo "<td><input type=\"image\" name=\"antwort\" src=\"button.php?type=menue&m_text=Antwort&m_fs=10&m_form=spitz&bg=lightblue\" alt=\"antworten\"></td>\n";
            echo "<td><input type=\"image\" name=\"weiterleiten\" src=\"button.php?type=menue&m_text=Weiterleiten&m_fs=10&m_form=spitz&bg=lightblue\" alt=\"weiterleiten\"></td>\n";
          } elseif ($this->formdata["04_richtung"]=="A"){
            echo "<td><input type=\"image\" name=\"weiterleiten\" src=\"button.php?type=menue&m_text=Weiterleiten&m_fs=10&m_form=spitz&bg=lightblue\" alt=\"weiterleiten\"></td>\n";
          }
        break;

        case "FM-Eingang":
        case "Stab_schreiben":
        case "Stab_korrigieren":
        case "FM-Eingang_Anhang":
        case "Stab_gesprnoti":
          echo "<td>\n";
          echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
          echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
            // AnhÃ¤nge
          echo "<input type=\"image\" name=\"anhang_plus\" src=\"".$conf_design_path."/attachment.gif\" alt=\"Anhang anfuegen\">\n";
          echo "</td>\n";
          echo "<td><input type=\"image\" name=\"absenden\" src=\"button.php?type=menue&m_text=absenden&m_fs=10&m_form=rund&bg=lightblue\" alt=\"absenden\"></td>\n";
          echo "<td><input type=\"image\" name=\"abbrechen\" src=\"button.php?type=menue&m_text=abbrechen&m_fs=10&m_form=rund&bg=lightblue\" alt=\"abbrechen\"></td>\n";
        break;

        case "FM-Ausgang":
        case "LdF-Eingang":
        case "LdF-Ausgang":
          echo "<td>\n";
          echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
          echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
          echo "<input type=\"image\" name=\"absenden\" src=\"button.php?type=menue&m_text=absenden&m_fs=10&m_form=rund&bg=lightblue\" alt=\"absenden\">\n";
          echo "</td>\n";
          echo "<td><input type=\"image\" name=\"abbrechen\" src=\"button.php?type=menue&m_text=abbrechen&m_fs=10&m_form=rund&bg=lightblue\" alt=\"abbrechen\"></td>\n";
          if ($this->task === "FM-Ausgang") {
            echo "<td><button type=\"submit\" ".
              "name=\"transport_nicht_moeglich_x\" value=\"1\" ".
              "class=\"estab-danger\" formnovalidate ".
              "title=\"Rückgabegrund angeben und an LdF zurückgeben\">".
              "Beförderung nicht möglich – zurück an LdF</button></td>\n";
          }
          if (
            $this->task === "FM-Ausgang"
            && $this->formdata["04_richtung"]=="A"
          ){
            echo "<td><input type=\"image\" name=\"antwort\" src=\"button.php?type=menue&m_text=Antwort&m_fs=10&m_form=spitz&bg=lightblue\" alt=\"antworten\"></td>\n";
          }

        break;
        case "FM-Admin":
        case "SI-Admin":
          echo "<td colspan=\"2\"><strong>Abgeschlossener Nachweis – ".
            "schreibgeschützt</strong></td>\n";
        break;

        case "Stab_sichten":
          $isOutgoingFormalReview =
            ($this->formdata ["04_richtung"] ?? "") === "A";
          echo "<td data-estab-formal-review=\"".
               ($isOutgoingFormalReview ? "outgoing" : "incoming")."\">\n";
          echo "<input type=\"hidden\" name=\"00_lfd\" value=\"".$this->lfd."\">\n";
          echo "<input type=\"hidden\" name=\"task\" value=\"".$this->task."\">\n";
          $approvalLabel = $isOutgoingFormalReview
            ? "Formal geprüft – an FmZt"
            : "Sichtung abschließen";
          echo "<button type=\"submit\" name=\"absenden_x\" value=\"1\">".
               estab_message_html ($approvalLabel)."</button>\n";
          echo "</td>\n";
          if ($isOutgoingFormalReview) {
            echo "<td><button type=\"submit\" name=\"zurueckweisen_x\" ".
                 "value=\"1\" class=\"estab-danger\" ".
                 "title=\"Ein Rückgabegrund im Feld Vermerke ist Pflicht\">".
                 "An Verfasser zurückgeben</button></td>\n";
          }
          echo "<td><input type=\"image\" name=\"abbrechen\" ".
               "src=\"button.php?type=menue&amp;m_text=abbrechen&amp;m_fs=10".
               "&amp;m_form=rund&amp;bg=lightblue\" alt=\"abbrechen\"></td>\n";
        break;
     } // switch
    echo "</TR>";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</TD>\n";
    echo "</TR>\n";
    echo "</TBODY>\n";
    echo "</TABLE>\n";
//    echo "</fieldset>\n";
  }


/*****************************************************************************\
   Funktion    :  showerrorinfo
   Beschreibung:  Ausgabe Fehlermeldung Info

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function showerrorinfo ($errorat)
  {   include ("../4fcfg/config.inc.php");
    echo "<a href=\"../language/german/helptext.php?Errorart=".$errorat.
         "\" onclick=\"FensterOeffnen(this.href); return false\"><img src=\"".
         $conf_design_path."/warning.gif\" alt=\"Fehler\" width=\"24\"height=\"24\" title=\"Fehler\"></a>";
  }



  var $formbgcolor ; // Hintergrundfarbe

/*****************************************************************************\
   Funktion     :  plot_form

   Beschreibung :  Ausgabe des Formulars

   (C) Hajo Landmesser IuK Kreis Heinsberg
   mailto://hajo.landmesser@iuk-heinsberg.de
\*****************************************************************************/
  function plot_form (){
    $this->plot_official_message_form ();
  }

  /**
   * Retained as a migration reference while all runtime rendering uses the
   * official, accessible grid from official_message_form.php.
   */
  function plot_legacy_form (){

    include ("../4fcfg/config.inc.php");
    include ("../4fcfg/para.inc.php");
    include ("../4fcfg/dbcfg.inc.php");
    include ("../4fcfg/e_cfg.inc.php");
    include ("../4fcfg/fkt_rolle.inc.php");
    include ("../4fcfg/color.inc.php");

    $this->ziele (); // Ziele und Farben   $fktmsgbgcolor

    switch ($this->fktmsgbgcolor) {
      case "rt": $this->formbgcolor =  $cfg ["vbg"]  ["rt"] ; break;
      case "gn": $this->formbgcolor =  $cfg ["vbg"]  ["gn"] ; break;
      case "bl": $this->formbgcolor =  $cfg ["vbg"]  ["bl"] ; break;
      case "ge": $this->formbgcolor =  $cfg ["vbg"]  ["ge"] ; break;
        default: $this->formbgcolor =  $cfg ["vbg"]  ["default"] ;
    }
    $this->feldbgcolor ();
    $this->get_access_by_task ($this->task);

    if (debug){
      echo "<big><big>TASK TASK TASK===".$this->task."</big></big><br><b>";
    }

    pre_html ("N","Formular ".$this->task." ".$conf_4f ["Titelkurz"]." ".$conf_4f ["Version"], ""); // Normaler Seitenaufbau ohne Auffrischung
    echo "<body style=\"text-align: left; background-color: rgb(220,220,255); \">\n"; //".$this->formbgcolor.";\">\n";
    if ((string) $this->formdata ["estab_route_error"] !== "") {
      echo "<div class=\"estab-alert estab-alert--danger\" role=\"alert\">".
        estab_message_html ($this->formdata ["estab_route_error"]).
        "</div>\n";
    }

    include_once ("./katego.php");

    $dirtyInitial = $this->hasUnsavedValidationData
      ? " data-estab-dirty-initial"
      : "";
    echo "<FORM style=\"\" method=\"POST\" action=\"".$conf_4f ["MainURL"].
         "\" name=\"4fach\" data-estab-dirty-guard ".
         "data-estab-requires-incident".$dirtyInitial.">\n";
    echo estab_csrf_field ();
    $this->show_menue_buttons (2, "oben");
    echo "<!-- **********4fachform.php-697-anfang-HAUPTTABELLE*********** -->\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 810px;\" border=\"1\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"height: 113px; width: 860px;\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; height: 32px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    /****************************************************************************\
                              F M  -  B E T R I E B S S T E L L E
    \****************************************************************************/
    // Zeile, Spalte 1,1    EINGANG    1  1   Eingang
    echo "<td style=\"width: 230px; background-color: ".$this->bg[1].";\">\n";
    echo "<div style=\"text-align: center; width: 250px;\">EINGANG</div>\n"; // 200px
    echo "</td>\n";
    // Zeile, Spalte 1,2    AUSGANG    2  2   Ausgang Annahmevermerk Befoerderungsvermerk
    echo "<td style=\"text-align: center; background-color: ".$this->bg[2]."; width: 400px;\">\n"; // 427px
    echo "<div style=\"text-align: center; width: 405px;\">AUSGANG</div>\n"; // 450px
    // Zeile, Spalte 1,3    Nachweisung   8   4   Nachweis Nummer E A
    echo "<td style=\"text-align: center; width: 150px; background-color: ".$this->bg[4].";\">Nachweisnummer</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    /****************************************************************************\
    |  Zeile, Spalte 2 , 1   Aufnahmevermerk  1   1   Eingang                    |
    \****************************************************************************/
    $incomingMediumEditable =
      $this->feld [1] || $this->task === "LdF-Eingang";
    if (!$incomingMediumEditable){
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurueck !!!
      echo "<input id=\"f_01_medium\" type=\"hidden\" name=\"01_medium\" value=\"".$this->safe_message_value ("01_medium")."\">\n";
    }
    else {
      $param = "";
    } 							
    echo "<td style=\"background-color: ".$this->bg[1]."; width: 250px; text-align: center; vertical-align: top;\"><!--005-->\n";
    echo "<div id=\"estab-incoming-medium-label\" ".
      "style=\"text-align: center;\">Aufnahmevermerk</div>\n";
    if (
      ($this->errorselect ["01_medium"] == false)
      && $incomingMediumEditable
    ) {
      $this->showerrorinfo ("01_medium");
    }
    echo "<div role=\"radiogroup\" ".
      "aria-labelledby=\"estab-incoming-medium-label\">\n";
    if ($this->formdata["01_medium"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_fe\"><input id=\"f_01_medium_fe\" name=\"01_medium\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe</label>";
    if ($this->formdata["01_medium"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_fu\"><input id=\"f_01_medium_fu\" name=\"01_medium\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu</label>";
    if ($this->formdata["01_medium"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_me\"><input id=\"f_01_medium_me\" name=\"01_medium\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me</label>";
    if (strcasecmp ((string) $this->formdata["01_medium"], "FAX") === 0) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_fax\"><input id=\"f_01_medium_fax\" name=\"01_medium\" value=\"FAX\" type=\"radio\" ".$param.$sel.">Fax</label>";
    if ($this->formdata["01_medium"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_fs\"><input id=\"f_01_medium_fs\" name=\"01_medium\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS</label>";
    if ($this->formdata["01_medium"]=="@") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<label for=\"f_01_medium_at\"><input id=\"f_01_medium_at\" name=\"01_medium\" value=\"@\" type=\"radio\" ".$param.$sel.">@</label>";
    echo "</div>\n";
    if ($this->task === "LdF-Eingang") {
      $originalMedium = (string) (
        $this->formdata ["incoming_transport_original_medium"] ?? ""
      );
      echo "<p class=\"estab-message-transport-confirmation\">".
        "<strong>Eingangsweg durch LdF bestätigen</strong><br>".
        "Prüfen Sie den von A/W aufgenommenen Weg. Bei einer Korrektur ".
        "ist eine Begründung erforderlich.<br>".
        "<span data-estab-incoming-transport-original=\"".
        estab_message_html ($originalMedium)."\">Von A/W erfasst: <strong>".
        estab_message_html (estab_message_medium_text ($originalMedium)).
        "</strong></span></p>\n";
      if (
        $this->errorselect ["incoming_transport_confirmed"] == false
        && $this->hasUnsavedValidationData
      ) {
        $this->showerrorinfo ("incoming_transport_confirmed");
      }
      $transportConfirmed = hash_equals (
        "1",
        (string) $this->formdata ["incoming_transport_confirmed"]
      ) ? " checked" : "";
      echo "<label for=\"f_incoming_transport_confirmed\" ".
        "class=\"estab-message-transport-confirm-checkbox\">".
        "<input id=\"f_incoming_transport_confirmed\" type=\"checkbox\" ".
        "name=\"incoming_transport_confirmed\" value=\"1\" required ".
        "data-estab-incoming-transport-confirmation=\"required\"".
        $transportConfirmed."> ".
        "Eingangsweg geprüft und bestätigt</label>\n";
      echo "<label for=\"f_incoming_transport_correction_reason\" ".
        "class=\"estab-message-transport-reason-label\">".
        "Begründung nur bei Änderung</label>\n";
      echo "<textarea id=\"f_incoming_transport_correction_reason\" ".
        "name=\"incoming_transport_correction_reason\" maxlength=\"500\" ".
        "rows=\"2\" cols=\"24\" ".
        "placeholder=\"Warum wurde der Eingangsweg korrigiert?\">".
        estab_message_html (
          $this->formdata ["incoming_transport_correction_reason"]
        )."</textarea>\n";
    }
    if (!$this->feld [1]){
      if ( ( $this->formdata["01_datum"] != "") or
           ( $this->formdata["01_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          echo $this->safe_message_value ("01_datum")."&nbsp; &nbsp;".$this->safe_message_value ("01_zeichen");
          echo "</b></div>";
        } else {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->safe_message_value ("01_datum")."&nbsp; &nbsp;".$this->safe_message_value ("01_zeichen");
        echo "</b></div>";
        }
      } else {
        echo "<br>";
      }
    } else {
    if ( $this->errorselect ["01_datum"] == false ){
      $this->showerrorinfo ("01_datum");
    }
    echo "<input id=\"f_01_datum\" maxlength=\"13\" size=\"13\" name=\"01_datum\" value=\"".$this->safe_message_value ("01_datum")."\">\n";
    if (in_array (
      $this->task,
      array ("FM-Eingang", "FM-Eingang_Anhang", "Stab_gesprnoti"),
      true
    )) {
      echo "<strong id=\"f_01_zeichen\" data-estab-readonly=\"true\" ".
           "aria-label=\"Aufnahmezeichen wird aus der Anmeldung übernommen\">".
           $this->safe_message_value ("01_zeichen")."</strong>\n";
    } else {
      if ( $this->errorselect ["01_zeichen"] == false ){
        $this->showerrorinfo ("01_zeichen");    }
      echo "<input id=\"f_01_zeichen\" maxlength=\"6\" size=\"6\" name=\"01_zeichen\" value=\"".$this->safe_message_value ("01_zeichen")."\">\n";
    }
    }
    echo "<div style=\"text-align: center;\"><label for=\"01_datum\">Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;</label><label for=\"01_zeichen\"></label>Zeichen</td></div>";
    /****************************************************************************\
    | Zeile, Spalte 2 , 2+3  2   2   Ausgang Annahmevermerk +
    |                         4  3   Ausgang Beförderungsvermerk
    02_zeit
    02_zeichen
    \****************************************************************************/
    echo "<td style=\"width: 400px; background-color: ".$this->bg[2].";\"><!--006-->\n"; // 427px
    echo "<table style=\"text-align: \"center\"; background-color: ".$this->rbl_bg_color."; width: 400px; height: 80px; margin-left: auto; margin-right: auto;\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">\n";
    echo "<tbody><!--table + tbody 003-->\n";
    echo "<tr>\n";
    echo "<td style=\"height: 80px; width: 150px; background-color: ".$this->bg[2]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Annahmevermerk<br></div>\n";
    if (!$this->feld[2]) {
      if ( ( $this->formdata["02_zeit"] != "" ) or
           ( $this->formdata["02_zeichen"] != "" ) ) {
        echo "<div style=\"text-align: center;\"><b>";
        echo $this->safe_message_value ("02_zeit")."&nbsp; &nbsp;".$this->safe_message_value ("02_zeichen");
        echo "</b></div>";
      } else {
        echo "<br>";
      }
    } else {
      if ( $this->errorselect ["02_zeit"] == false ){
      $this->showerrorinfo ("02_zeit");      }
      echo "<input id=\"f_02_zeit\" maxlength=\"13\" size=\"13\" name=\"02_zeit\" value=\"".$this->safe_message_value ("02_zeit")."\">&nbsp;\n";
      if (in_array ($this->task, array ("LdF-Eingang", "LdF-Ausgang"), true)) {
        echo "<strong id=\"f_02_zeichen\" data-estab-readonly=\"true\" ".
             "aria-label=\"Zeichen wird aus der Anmeldung übernommen\">".
             $this->safe_message_value ("02_zeichen")."</strong><br>\n";
      } else {
        if ( $this->errorselect ["02_zeichen"] == false ){
          $this->showerrorinfo ("02_zeichen");
        }
        echo "<input id=\"f_02_zeichen\" maxlength=\"6\" size=\"6\" name=\"02_zeichen\" value=\"".$this->safe_message_value ("02_zeichen")."\"><br>\n";
      }
    }
    echo "<div style=\"text-align: center;\">";
    echo "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";
    echo "<td style=\"height: 80px; width: 220px; background-color: ".$this->bg[3]."; text-align: center; vertical-align: top;\">\n";
    echo "<div style=\"text-align: center;\">Beförderungsvermerk<br></div>\n";
    if (!$this->feld [3]){
      if ( ( $this->formdata["03_datum"]   != "") or
           ( $this->formdata["03_zeichen"] != "" ) ) {
        if ( posttakzeit ) {
          echo "<div style=\"text-align: center;\"><b>";
          $takzeit = konv_datetime_taktime ($this->formdata["03_datum"]);
          echo estab_message_html ($takzeit)."&nbsp; &nbsp;".$this->safe_message_value ("03_zeichen");
          echo "</b></div>";
        } else {
          echo "<div style=\"text-align: center;\"><b>";
          echo $this->safe_message_value ("03_datum")."&nbsp; &nbsp;".$this->safe_message_value ("03_zeichen");
          echo "</b></div>";
        }
      }else {
        echo "<br>";
      }
    } else {
      if ( $this->errorselect ["03_datum"] == false ){
      $this->showerrorinfo ("03_datum");      }
      echo "<input id=\"f_03_datum\" maxlength=\"13\" size=\"13\" name=\"03_datum\" value=\"".$this->safe_message_value ("03_datum")."\">\n";
      if ( $this->errorselect ["03_zeichen"] == false ){
      $this->showerrorinfo ("03_zeichen");      }

      echo "<input id=\"f_03_zeichen\" maxlength=\"6\" size=\"6\" name=\"03_zeichen\" value=\"".$this->safe_message_value ("03_zeichen")."\"><br>\n";
    }
    echo "<div style=\"text-align: center;\">";
    echo "Datum &nbsp; &nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</div>";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 2 , 4    8   4   Nachweis Nummer E A
    04_richtung;
    04_nummer;
    \****************************************************************************/
    echo "<td style=\"width: 150px; background-color: ".$this->bg[4]."; text-align: left; vertical-align: top;\">Nachweis Nr.";
    if (!$this->feld[4]) {
        echo "<div style=\"text-align: center;\"><b><big><big><big>";
        echo $this->safe_message_value ("04_richtung")."&nbsp; &nbsp;".$this->safe_message_value ("04_nummer");
        echo "</big></big></big></b></div>";
        echo "<input id=\"f_04_richtung\" type=\"hidden\" name=\"04_richtung\" value=\"".$this->safe_message_value ("04_richtung")."\">\n";
        echo "<input id=\"f_04_nummer\" type=\"hidden\" name=\"04_nummer\" value=\"".$this->safe_message_value ("04_nummer")."\">\n";
    } else {
      echo "<input id=\"f_04_nummer\" maxlength=\"6\" size=\"6\" name=\"04_nummer\" value=\"".$this->safe_message_value ("04_nummer")."\"><br>\n";
      if (!$this->feld[4]) {
        $param = " disabled ";
        // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
        echo "<input id=\"f_04_richtung\" type=\"hidden\" name=\"04_richtung\" value=\"".$this->safe_message_value ("04_richtung")."\">\n";
      }
      else {
        $param = "";
      }
      if ($this->formdata["04_richtung"]=="E") {$sel = "checked=\"checked\"";} else {$sel = "";}
      echo "<input id=\"f_04_richtung\" name=\"04_richtung\" value=\"E\" type=\"radio\" ".$param.$sel.">E<br>\n";
      if ($this->formdata["04_richtung"]=="A") {$sel = "checked=\"checked\"";} else {$sel = "";}
      echo "<input id=\"f_04_richtung\" name=\"04_richtung\" value=\"A\" type=\"radio\" ".$param.$sel.">A<br>\n";
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    // Zeile, Spalte 3 , 1  Rufname der Gegenst. 16   5   Rufname der Gegenstelle
    echo "<td>\n";                                                                            
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 820px; height: 52px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";         
    echo "<td style=\"width: 227px; background-color: ".$this->bg[5].";\">Rufname der Gegenstelle/<br>\n";
    echo "Spruchkopf</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 3 , 2   16   5   Rufname der Gegenstelle
    05_gegenstelle
    \****************************************************************************/
    echo "<td style=\"text-align: center; background-color: ".$this->bg[5]."; width: 580px;\">\n";
    if  (!$this->feld[5]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("05_gegenstelle");
      echo "</b></div>";
    } else {
       echo "<div class=\"estab-message-suggestion-control\">\n";
       echo "<input id=\"f_05_gegenstelle\" maxlength=\"128\" size=\"50\" name=\"05_gegenstelle\" value=\"".$this->safe_message_value ("05_gegenstelle")."\"".$this->message_suggestion_input_attributes ("05_gegenstelle").">\n";
       $this->show_message_suggestions ("05_gegenstelle");
       echo "</div>\n";
    }
    echo "</td>";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    // Zeile 4
    echo "<tr> ";
    echo "<td style=\"width: 821px; height: 40px;\">";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 820px; height: 46px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 4 , 1   32   6   Befoerderungsweg
    echo "<td style=\"width: 131px; background-color: ".$this->bg[6]."; font-size:11px\">Beförderungsweg:</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 4 , 2   32   6   Beförderungsweg
    06_befweg
    \****************************************************************************/
    if ($this->task === "LdF-Ausgang") {
      echo "<td colspan=\"2\" style=\"text-align: left; background-color: ".
        $this->bg[6].";\">\n";
      echo "<label for=\"f_fernmeldeplan_eintrag_id\"><b>".
        "Gültiger S6-Fernmeldeweg</b></label><br>\n";
      echo "<select id=\"f_fernmeldeplan_eintrag_id\" ".
        "name=\"fernmeldeplan_eintrag_id\" required>\n";
      echo "<option value=\"\">Bitte Fernmeldeweg auswählen</option>\n";
      $selectedRoute = (string) (
        $this->formdata ["fernmeldeplan_eintrag_id"] ?? ""
      );
      foreach ($this->activeTelecomRoutes as $route) {
        $routeId = (string) $route ["fernmeldeplan_eintrag_id"];
        $routeParts = array_values (array_filter (array (
          trim ((string) ($route ["betriebsstelle"] ?? "")),
          trim ((string) ($route ["rufname"] ?? "")),
          trim ((string) ($route ["kanal"] ?? "")),
          trim ((string) ($route ["bandlage"] ?? "")),
          trim ((string) ($route ["verkehrsform"] ?? "")),
        ), static fn ($part) => $part !== ""));
        $routeLabel = "Plan v".(int) $route ["plan_version"]." · ".
          (string) $route ["medium"]." · ".implode (" · ", $routeParts);
        echo "<option value=\"".estab_message_html ($routeId)."\"".
          ($selectedRoute === $routeId ? " selected" : "").">".
          estab_message_html ($routeLabel)."</option>\n";
      }
      echo "</select>\n";
      if ($this->activeTelecomRoutes === array ()) {
        echo "<p class=\"estab-field-error\">Kein aktuell gültiger, ".
          "freigegebener S6-Fernmeldeplan verfügbar.</p>\n";
      } else {
        echo "<small>Medium und Nachweisweg werden unveränderbar aus ".
          "diesem Plan übernommen.</small>\n";
      }
      echo "</td>";
    } else {
    echo "<td style=\"text-align: center; width: 446px; background-color: ".$this->bg[6].";\">\n";
    if (!$this->feld[6]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("06_befweg");
      echo "</b></div>";
    } else {
      echo "<input id=\"f_06_befweg\" maxlength=\"50\" size=\"50\" name=\"06_befweg\" value=\"".$this->safe_message_value ("06_befweg")."\">\n";
    }
    echo "</td>";
    /****************************************************************************\
    // Zeile, Spalte 4 , 3   32   6   Beförderungsweg
    06_befwegausw
    \****************************************************************************/
    if (!$this->feld[6]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input id=\"f_06_befwegausw\" type=\"hidden\" name=\"06_befwegausw\" value=\"".$this->safe_message_value ("06_befwegausw")."\">\n";
    }
    else {
      $param = "";
    }
    echo "<td style=\"width: 230px; background-color: ".$this->bg[6].";\">";
    if ($this->formdata["06_befwegausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_fe\" name=\"06_befwegausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["06_befwegausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_fu\" name=\"06_befwegausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["06_befwegausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_me\" name=\"06_befwegausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if (strcasecmp ((string) $this->formdata["06_befwegausw"], "FAX") === 0) {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_fax\" name=\"06_befwegausw\" value=\"FAX\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["06_befwegausw"]=="FS") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_fs\" name=\"06_befwegausw\" value=\"FS\" type=\"radio\" ".$param.$sel.">FS";
    if ($this->formdata["06_befwegausw"]=="@") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_06_befwegausw_at\" name=\"06_befwegausw\" value=\"@\" type=\"radio\" ".$param.$sel.">@";
    }
    echo "</tr>\n";
    if ($this->task === "FM-Ausgang") {
      $routeSummary = implode (
        " · ",
        array_values (array_filter (array (
          trim ((string) $this->formdata ["06_befwegausw"]),
          trim ((string) $this->formdata ["06_befweg"]),
        ), static fn (string $part): bool => $part !== ""))
      );
      $confirmationChecked =
        (string) $this->formdata ["transportweg_bestaetigt"] === "1"
          ? " checked"
          : "";
      echo "<tr data-estab-transport-confirmation=\"required\">\n";
      echo "<td style=\"background-color: ".$this->bg[6].";\">".
        "<strong>Beförderungsnachweis:</strong></td>\n";
      echo "<td colspan=\"2\" style=\"background-color: ".$this->bg[6].
        "; text-align:left;\">\n";
      echo "<p><strong>Disponierter S6-Weg:</strong> ".
        estab_message_html ($routeSummary)."</p>\n";
      echo "<label for=\"f_transportweg_bestaetigt\">".
        "<input id=\"f_transportweg_bestaetigt\" type=\"checkbox\" ".
        "name=\"transportweg_bestaetigt\" value=\"1\" required".
        $confirmationChecked."> ".
        "Ich bestätige: Die Nachricht wurde über diesen Weg befördert.".
        "</label>\n";
      echo "<p><label for=\"f_transport_rueckgabegrund\">".
        "<strong>Falls die Beförderung nicht möglich ist:</strong></label><br>\n";
      echo "<textarea id=\"f_transport_rueckgabegrund\" ".
        "name=\"transport_rueckgabegrund\" maxlength=\"2000\" rows=\"3\" ".
        "placeholder=\"Pflichtangabe bei Rückgabe, z. B. Funkweg ausgefallen\">".
        estab_message_html ($this->formdata ["transport_rueckgabegrund"]).
        "</textarea></p>\n";
      echo "<p>Mit „Beförderung nicht möglich“ geht die Nachricht samt ".
        "Begründung zurück an LdF. Nur LdF kann anschließend einen anderen ".
        "aktiven S6-Weg disponieren.</p>\n";
      echo "</td>\n";
      echo "</tr>\n";
    }
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                          F M  -  B E T R I E B S S T E L L E
    ********************************************************************************************
    ********************************************************************************************
                                            I N H A L T
    */
    echo "<tr>\n";
    echo "<td style=\"width: 831px; height: 0px;\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 821px; height: 64px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    /****************************************************************************\
    // Zeile, Spalte 5,1   64 7   Durchsage / Spruch
    \****************************************************************************/
    if (!$this->feld[7]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input id=\"f_07_durchspruch\" type=\"hidden\" name=\"07_durchspruch\" value=\"".$this->safe_message_value ("07_durchspruch")."\">\n";
    }
    else {
      $param = "";}
    echo "<td style=\"width: 126px; background-color:".$this->bg[7]."; font-size:10px\">\n";
    if ($this->formdata["07_durchspruch"]=="D") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_07_durchspruch\" name=\"07_durchspruch\" value=\"D\" type=\"radio\" ".$param.$sel.">DURCHSAGE<br>\n";
    if ($this->formdata["07_durchspruch"]=="S") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_07_durchspruch\" name=\"07_durchspruch\" value=\"S\" type=\"radio\" ".$param.$sel.">Spruch</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 5,2   128    8   Befoerderungshinweis
    \****************************************************************************/
    echo "<td style=\"text-align: left; width: 120px; background-color: ".$this->bg[8].";  font-size:10px\">Beförderungshinweis:<br>Tel.</td>\n"; //140px
    /****************************************************************************\
    // Zeile, Spalte 5,3   128    8   Befoerderungshinweis
    08_befhinweis
    \****************************************************************************/
    echo "<td style=\"width: 294px; background-color: ".$this->bg[8].";  font-size:10px\">\n";
    if  (!$this->feld[8]) {
      echo "<div style=\"text-align: left;\"><b>";
      echo $this->safe_message_value ("08_befhinweis");
      echo "</b></div>";
    } else {
      echo "<input id=\"f_08_befhinweis\" maxlength=\"40\" size=\"40\" name=\"08_befhinweis\" value=\"".$this->safe_message_value ("08_befhinweis")."\">";
    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 5,4   128    8   Befoerderungshinweis
    08_befhinwausw
    \****************************************************************************/
    if  (!$this->feld[8]) {
      $param = " disabled ";
      // Radio Button die deaktiviert sind liefern keinen Wert zurck !!!
      echo "<input id=\"f_08_befhinwausw\" type=\"hidden\" name=\"08_befhinwausw\" value=\"".$this->safe_message_value ("08_befhinwausw")."\">\n";
    }
    else {
      $param = "";
    }
    echo "<td style=\"width: 225px; background-color: ".$this->bg[8].";\">\n";
    if ($this->formdata["08_befhinwausw"]=="Fe") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_08_befhinwausw_fe\" name=\"08_befhinwausw\" value=\"Fe\" type=\"radio\" ".$param.$sel.">Fe";
    if ($this->formdata["08_befhinwausw"]=="Fu") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_08_befhinwausw_fu\" name=\"08_befhinwausw\" value=\"Fu\" type=\"radio\" ".$param.$sel.">Fu";
    if ($this->formdata["08_befhinwausw"]=="Me") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_08_befhinwausw_me\" name=\"08_befhinwausw\" value=\"Me\" type=\"radio\" ".$param.$sel.">Me";
    if ($this->formdata["08_befhinwausw"]=="Fax") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_08_befhinwausw_fax\" name=\"08_befhinwausw\" value=\"Fax\" type=\"radio\" ".$param.$sel.">Fax";
    if ($this->formdata["08_befhinwausw"]=="@") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_08_befhinwausw_at\" name=\"08_befhinwausw\" value=\"@\" type=\"radio\" ".$param.$sel.">@";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."\" align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 820px; height: 100px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    /****************************************************************************\
    // Zeile, Spalte 6,1   Vorrangstufe     256   9   VORRANGSTUFE !!!
    09_vorrangstufe;
    \****************************************************************************/
    echo "<td style=\"width: 90px; background-color: ".$this->bg[9].";\">Vorrangstufe<br>\n";
    if (((($this->formdata["09_vorrangstufe"]) != "" )) or (!$this->feld[9])) {
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\"><big><big><b>";
      echo "<input id=\"09_vorrangstufe\" type=\"hidden\" name=\"09_vorrangstufe\" value=\"".$this->safe_message_value ("09_vorrangstufe")."\">\n";
      echo estab_message_html (
        estab_message_priority_label ($this->formdata ["09_vorrangstufe"])
      );
      echo "</big></big></b></div>";
    } else {
      echo "<select id=\"f_09_vorrangstufe\" ".$param.
        "name=\"09_vorrangstufe\" ".
        "aria-describedby=\"estab-priority-warning\" ".
        "style=\"text-align: center; background-color:".$this->bg[9].
        "; font-size:x-large; font-weight:bold; max-width:100%;\">\n";
      foreach (estab_message_priority_options () as $priorityOption) {
        $value = (string) $priorityOption ["value"];
        $selected = $this->formdata ["09_vorrangstufe"] === $value
          ? " selected"
          : "";
        echo "<option value=\"".estab_message_html ($value)."\"".
          $selected.">".
          estab_message_html ($priorityOption ["label"]).
          "</option>\n";
      }
      echo "</select>\n";
    }
    $priorityWarning = $this->feld [9]
      ? estab_message_priority_warning ("aaa")
      : estab_message_priority_warning (
          $this->formdata ["09_vorrangstufe"]
        );
    if ($priorityWarning !== "") {
      echo "<small id=\"estab-priority-warning\" ".
        "style=\"display:block; margin-top:0.35rem; font-size:0.72rem; ".
        "line-height:1.2;\">".
        estab_message_html ($priorityWarning).
        "</small>\n";
    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 6,2   Anschrift      512 10  Anschrift
    10_anschrift
    \****************************************************************************/
    echo "<td style=\"width: 600px; background-color: ".$this->bg[10].";\">";
    echo "Anschrift:";
    if ( $this->errorselect ["10_anschrift"] == false ){
      $this->showerrorinfo ("10_anschrift");    }
    echo "<br>\n";
    if (!$this->feld[10]) {
      echo "<input id=\"f_10_anschrift\" type=\"hidden\" name=\"10_anschrift\" value=\"".$this->safe_message_value ("10_anschrift")."\">\n";
      echo "<div style=\"text-align: center; font-size:24px; font-weight:900;\">";
      echo $this->safe_message_value ("10_anschrift") ;
      echo "</div>\n";
    } else {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea id=\"f_10_anschrift\" style=\"font-size:18px; font-weight:900;\" cols=\"40\" rows=\"2\" name=\"10_anschrift\">".$this->safe_message_value ("10_anschrift") ;
      echo "</textarea></div>\n";
    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 6,3   Gesprächsnotiz    1024 11  Gesprächsnotiz
    11_gesprnotiz
    \****************************************************************************/
    if (((($this->formdata["11_gesprnotiz"]) != "" )) or (!$this->feld[11])) {
      if ( $this->formdata["11_gesprnotiz"] ){$this->formdata["11_gesprnotiz"]= "on"; }
      echo "<input id=\"f_11_gesprnotiz\" type=\"hidden\" name=\"11_gesprnotiz\" value=\"".$this->safe_message_value ("11_gesprnotiz")."\">\n";
      $param = " disabled ";}
    else {
      $param = "";}
    echo "<td style=\"width: 110px; background-color: ".$this->bg[11].";\">Gesprächsnotiz<br>\n";
    echo "<div style=\"text-align: center;\">";
    if ($this->formdata["11_gesprnotiz"] == "on") {$sel = "checked=\"checked\"";} else {$sel = "";}
    echo "<input id=\"f_11_gesprnotiz\" name=\"11_gesprnotiz\" type=\"checkbox\" ".$param.$sel."><br>\n";
    echo "</div>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"TOP\">\n";
    /****************************************************************************\
    // Zeile, Spalte 7,1  Inhalt   2048   12  Inhalt, Abfassungszeit
    12_inhalt
    \****************************************************************************/
    echo "<table style=\"text-align: left; width: 820px; height: 216px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    if  (!$this->feld[12]) {
      $param = " readonly ";}
    else {
      $param = "";}
    echo "<td valign=\"TOP\" style=\"background-color: ".$this->bg[12].";\">";
    echo "Inhalt/Text:";
    if ( $this->errorselect ["12_inhalt"] == false ){
      $this->showerrorinfo ("12_inhalt");
    }
    echo "<br>\n";
    if  ($this->feld[12]) {
      echo "<div style=\"text-align: center;\">";
      echo "<textarea id=\"f_12_inhalt\" style=\"font-size:18px; font-weight:900;\" cols=\"65\" rows=\"10\" name=\"12_inhalt\"".$param.">".$this->safe_message_value ("12_inhalt");
      echo "</textarea></div>\n";
    } else {
      echo "<div style=\"text-align: left; font-size:18px; font-weight:400;\">"; //900
      echo "<input id=\"f_12_inhalt\" type=\"hidden\" name=\"12_inhalt\" value=\"".$this->safe_message_value ("12_inhalt")."\">\n";
      echo nl2br ($this->safe_message_value ("12_inhalt")) ;
      echo "</div>";
    }
      // Sind Anhaege definiert? Wenn ja, anzeigen.
    if ($this->formdata["12_anhang"] != ""){
      echo "<input id=\"f_12_anhang\" type=\"hidden\" name=\"12_anhang\" value=\"".$this->safe_message_value ("12_anhang")."\">\n";
      $this->list_anhang ();
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "<td style=\"text-align: left; background-color: ".$this->rbl_bg_color."; align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 820px; height: 35px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    /****************************************************************************\
    // Zeile, Spalte 8,1     2048 12  Inhalt, Abfassungszeit
    \****************************************************************************/
    echo "<td style=\"width: 125px; background-color: ".$this->bg[12].";\">Abfassungszeit:";
    if ( $this->errorselect ["12_abfzeit"] == false ){
      $this->showerrorinfo ("12_abfzeit");    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 8,2     4096 13  Absender, Einheit
    12_abfzeit
    \****************************************************************************/
    echo "<td style=\"width: 600px; background-color: ".$this->bg[13].";\">\n";

    if (!$this->feld [12]){
      echo "<div style=\"text-align: left; font-size:24px; font-weight:900;\">";
      echo $this->safe_message_value ("12_abfzeit") ;
      echo "<input id=\"f_12_abfzeit\" type=\"hidden\" name=\"12_abfzeit\" value=\"".$this->safe_message_value ("12_abfzeit")."\">\n";
      echo "</div>\n";
    } else {
      echo "<input id=\"f_12_abfzeit\" maxlength=\"13\" size=\"13\" name=\"12_abfzeit\" value=\"".$this->safe_message_value ("12_abfzeit")."\">";
    }
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; background-color: ".$this->rbl_bg_color."; width: 820px; height: 54px;\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    // Zeile, Spalte 9,1    4096  13  Absender, Einheit
    $senderAssignedByLead = in_array (
      $this->task,
      array (
        "FM-Eingang", "FM-Eingang_Anhang"
      ),
      true
    );
    echo "<td style=\"width: 100px; background-color: ".$this->bg[13].";\">Absender";
    if (
      !$senderAssignedByLead
      && $this->errorselect ["13_abseinheit"] == false
    ){
      $this->showerrorinfo ("13_abseinheit");    }
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 9,2    8192  14  Zeichen Funktion
    13_abseinheit
    \****************************************************************************/
    echo "<td style=\"text-align: left; width: 200px; background-color: ".$this->bg[13].";\">\n";
    if ($senderAssignedByLead) {
      echo "<strong id=\"f_13_abseinheit\" data-estab-readonly=\"true\">".
           "Wird durch LdF aus dem Rufnamen ergänzt</strong><br>\n";
    } elseif (!$this->feld [13]){
      echo "<b><big>".$this->safe_message_value ("13_abseinheit")."</big></b>" ;
      echo "<br>";
      echo "<input id=\"f_13_abseinheit\" type=\"hidden\" name=\"13_abseinheit\" value=\"".$this->safe_message_value ("13_abseinheit")."\">\n";
    }
    else {
      echo "<div class=\"estab-message-suggestion-control\">";
      echo "<input id=\"f_13_abseinheit\" style=\"font-size:16px; font-weight:900;\" maxlength=\"128\" size=\"30\"
              name=\"13_abseinheit\" value=\"".$this->safe_message_value ("13_abseinheit")."\"".$this->message_suggestion_input_attributes ("13_abseinheit").">";
      $this->show_message_suggestions ("13_abseinheit");
      echo "</div>\n";
    }
    echo "Einheit/Einrichtung/Stelle";
    echo "</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 9,3 Zeichen     8192 14  Zeichen Funktion
    14_zeichen
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
      echo "<b><big>".$this->safe_message_value ("14_zeichen")."</big></b><br>" ;
      echo "<input id=\"f_14_zeichen\" type=\"hidden\" name=\"14_zeichen\" value=\"".$this->safe_message_value ("14_zeichen")."\">\n";
    } else {
      echo "<input id=\"f_14_zeichen\" maxlength=\"6\" size=\"6\" name=\"14_zeichen\" value=\"".$this->safe_message_value ("14_zeichen")."\"><br>\n";
    }
    $externalAuthorMark = in_array (
      $this->task,
      array ("FM-Eingang", "FM-Eingang_Anhang"),
      true
    );
    echo ($externalAuthorMark ? "Externes Zeichen" : "Zeichen")."</td>\n";
    /****************************************************************************\
    // Zeile, Spalte 9,4 Funktion    8192 14  Zeichen Funktion
    14_funktion
    \****************************************************************************/
    echo "<td style=\"width: 100px; background-color: ".$this->bg[14].";\">\n";
    if (!$this->feld [14]){
      echo "<b><big>".$this->safe_message_value ("14_funktion")."</big></b><br>" ;
      echo "<input id=\"f_14_funktion\" type=\"hidden\" name=\"14_funktion\" value=\"".$this->safe_message_value ("14_funktion")."\">\n";
    } else {
      echo "<input id=\"f_14_funktion\" maxlength=\"25\" size=\"10\" name=\"14_funktion\" value=\"".$this->safe_message_value ("14_funktion")."\"".$param."><br>\n";
    }
    echo ($externalAuthorMark ? "Externe Funktion" : "Funktion")."</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    /*                                   I N H A L T
    ********************************************************************************************
    ********************************************************************************************
                                        S I C H T E R
    */
    echo "<tr>\n";
    echo "<td align=\"left\" valign=\"top\">\n";
    echo "<table style=\"text-align: left; width: 820px; height: 229px; background-color: ".$this->rbl_bg_color.";\" border=\"1\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    echo "<td style=\"width: 415px; background-color: ".$this->bg[15].";\">\n";
    echo "<table style=\"text-align: left; width: 418px; height: 65px;\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    echo "<tr>\n";
    /****************************************************************************\
    // Zeile, Spalte 10,1 Quittung     16384  15  Quittung Sichter
    15_quitdatum
    15_quitzeichen
    \****************************************************************************/
    echo "<td style=\"width: 109px; background-color: ".$this->bg[15].";\">Quittung:";
    echo "</td>\n";
    echo "<td style=\"width: 289px; background-color: ".$this->bg[15].";\">\n";
    $immutableAdminTimestamp = in_array ($this->task, array ("FM-Admin", "SI-Admin"), true);
    $reviewIdentityBound = $this->task === "Stab_sichten";
    if (!$this->feld [15]){
      echo "<div style=\"text-align: left;\">";
      echo $this->safe_message_value ("15_quitdatum")."&nbsp;&nbsp;".$this->safe_message_value ("15_quitzeichen");
      echo "</div>\n";
    } else {
      if ($immutableAdminTimestamp || $reviewIdentityBound) {
        echo "<span id=\"f_15_quitdatum\" data-estab-readonly=\"true\" aria-label=\"Quittierungszeitpunkt schreibgeschützt\">".$this->safe_message_value ("15_quitdatum")."</span>\n";
        echo "<input id=\"f_15_quitdatum_value\" type=\"hidden\" name=\"15_quitdatum\" value=\"".$this->safe_message_value ("15_quitdatum")."\">&nbsp;\n";
      } else {
        if ( $this->errorselect ["15_quitdatum"] == false ){
          $this->showerrorinfo ("15_quitdatum");    }
        echo "<input id=\"f_15_quitdatum\" maxlength=\"13\" size=\"13\" name=\"15_quitdatum\" value=\"".$this->safe_message_value ("15_quitdatum")."\">&nbsp;\n";
      }
      if ($reviewIdentityBound) {
        echo "<strong id=\"f_15_quitzeichen\" data-estab-readonly=\"true\" ".
             "aria-label=\"Sichterzeichen wird aus der Anmeldung übernommen\">".
             $this->safe_message_value ("15_quitzeichen")."</strong><br>\n";
      } else {
        if ( $this->errorselect ["15_quitzeichen"] == false ){
          $this->showerrorinfo ("15_quitzeichen");    }
        echo "<input id=\"f_15_quitzeichen\" maxlength=\"6\" size=\"6\" name=\"15_quitzeichen\" value=\"".$this->safe_message_value ("15_quitzeichen")."\"><br>\n";
      }
    }
    echo $immutableAdminTimestamp
      ? "&nbsp;Uhrzeit (fest) &nbsp; &nbsp;Zeichen</td>\n"
      : "&nbsp;Uhrzeit &nbsp; &nbsp;Zeichen</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "<table style=\"text-align: left; width: 450px; height: 144px; background-color: ".$this->rbl_bg_color.";\" border=\"0\" cellpadding=\"1\" cellspacing=\"0\">\n";
    echo "<tbody>\n";
    /****************************************************************************\
    // Zeile, Spalte 11,1   32768 16  Ziele
    16_empf
    \****************************************************************************/
    if ((!$this->feld[16])) {
      $param = " disabled ";}
    else {
      $param = "";}
    $readonlyDistribution = $immutableAdminTimestamp
      || (
        $this->task === "Stab_sichten"
        && ($this->formdata ["04_richtung"] ?? "") === "A"
      );
    if ($readonlyDistribution) {
      $distribution = trim (
        str_replace ("_", " · ", (string) $this->formdata ["16_empf"]),
        " ,"
      );
      echo "<tr><td colspan=\"4\" data-estab-distribution-readonly=\"true\">";
      echo "<strong>Dokumentierte Verteilung:</strong> ";
      echo $distribution === "" ? "Keine" : estab_message_html ($distribution);
      echo "</td></tr>\n";
    } else {
    switch ($this->task) {
      case "SI-Admin":
      case "Stab_sichten":
      case "Stab_gesprnoti":
      case "FM-Admin":
      case "SI-Admin":
        for ($m=1; $m<=5; $m++){ // Zeilen
          echo "<tr>";
          for ($n=1; $n<=4; $n++){  // Spalten
            // rote Kopie geht an...
            if ( ( $this->empfarray [$m][$n]["fkt"] == $this->redcopy2 ) and
                 ( $this->feld[16]) ) { // Wenn Sichter aktiv und rote Kopie
              echo "<td style=\"width: 75px; background-color: rgb(255,0,0);\">";
            }else{
              echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
            }

            switch ($this->empfarray [$m][$n]["typ"]){
              case "cb":
                if ( $this->empfarray [$m][$n]["fkt"] == $this->redcopy2 ) {
                  $red_inactiv = " disabled ";
                } else {
                  $red_inactiv = " ";
                }

                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "gn" ) ) {
                  $selcbgn = "checked=\"checked\"";} else {$selcbgn = "";}

                if ( ( $this->empfarray [$m][$n]["checked"]) and
                     ( $this->empfarray [$m][$n]["cpycol"] == "bl" ) ) {
                  $selcbbl = "checked=\"checked\"";} else {$selcbbl = "";}

                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_gncopy\" type=\"radio\" ".$selcbgn.$red_inactiv." value=\"16_".$m.$n."_gn\">\n";

                echo "<a style=\"background-color:#0303FD;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."_bl\" type=\"checkbox\" ".$param.$selcbbl.$red_inactiv.">\n</a>";

                echo $this->empfarray [$m][$n]["fkt"] ;

              break;

              case "t":
                if ($this->empfarray [$m][$n]["fkt"] != ""){
                  echo $this->empfarray [$m][$n]["fkt"] ;
                } else {
                  echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
                }
              break;

              case "cbt":
                if ($this->empfarray [$m][$n]["checked"]) {$selcb = "checked=\"checked\"";} else {$selcb = "";}
                echo "<a style=\"background-color:#00B000;\">
                      <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
                echo "<input maxlength=\"8\" size=\"8\" value=\"".$this->empfarray [$m][$n]["fkt"]."\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
              break;
            }
          } // for $n
          echo "</tr>\n";
        } // for $m
      break;

      case "Stab_lesen":
      case "Stab_schreiben":
      case "Stab_korrigieren":
      case "LdF-Eingang":
      case "LdF-Ausgang":
      case "FM-Ausgang":
      case "FM-Eingang":
      case "FM-Eingang_Anhang":

    for ($m=1; $m<=5; $m++){ // Zeilen
      echo "<tr>";
      for ($n=1; $n<=4; $n++){  // Spalten
        echo "<td style=\"width: 75px; background-color: ".$this->bg[16].";\">";
        switch ($this->empfarray [$m][$n]["typ"]){

          case "cb":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel.">\n";
            echo $this->empfarray [$m][$n]["fkt"] ;
          break;

          case "t":
            if ($this->empfarray [$m][$n]["fkt"] != ""){
              echo $this->empfarray [$m][$n]["fkt"] ;
            } else {
              echo "<p><img src=\"null.gif\" alt=\"leer\"></p>";
            }
          break;

          case "cbt":
            if ($this->empfarray [$m][$n]["checked"]) {$sel = "checked=\"checked\"";} else {$sel = "";}
            echo "<a style=\"background-color:#00B000;\">
                  <input name=\"16_".$m.$n."\" value=\"16_".$m.$n."\" type=\"checkbox\" ".$param.$sel."></a>\n";
            echo "<input maxlength=\"8\" size=\"8\" value=\"".$this->empfarray [$m][$n]["fkt"]."\" name=\"16_empf_sonst_".$m.$n."\" ".$param."></td>\n";
          break;
        }

        echo "</td>\n";
      } // for $n
      echo "</tr>\n";
    } // for $m
   break;

    } // switsch $this->task
    }

    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";

    /****************************************************************************\
    // Zeile, Spalte 10,2  Vermerke      65536    17  Vermerke
    17_vermerke
    \****************************************************************************/
    echo "<td  valign=\"TOP\" style=\"text-align: left; width: 350px; background-color: ".$this->bg[17].";\">Vermerke:<br>\n";

    if (!$this->feld[17]) {
      echo nl2br ($this->safe_message_value ("17_vermerke"));
    } else {
      echo "<textarea cols=\"40\" rows=\"10\" name=\"17_vermerke\" ".$param.">".$this->safe_message_value ("17_vermerke")."</textarea>";
    }

    echo "</td>\n";

    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";
    echo "</td>\n";
    echo "</tr>\n";
    echo "</tbody>\n";
    echo "</table>\n";

    $this->show_menue_buttons (2, "unten");

    echo "</FORM>\n";
    $this->show_message_suggestion_script ();

  } // function plot_form

} // class

?>
