-- Preserve obsolete or stale telecommunications drafts as immutable evidence
-- while allowing S6 to leave the one-draft workflow and start again from the
-- currently active plan. ERSETZT is the existing terminal archive state; a
-- discarded draft is distinguished by its plan_draft_discarded audit event
-- and deliberately has no release identity or release timestamp.

DROP TRIGGER IF EXISTS `estab_dv94_fernmeldeplan_immutable`;
DELIMITER //
CREATE TRIGGER `estab_dv94_fernmeldeplan_immutable`
BEFORE UPDATE ON `nv_fernmeldeplaene`
FOR EACH ROW
BEGIN
  IF NOT (NEW.`fernmeldeplan_id` <=> OLD.`fernmeldeplan_id`)
     OR NOT (NEW.`einsatz_id` <=> OLD.`einsatz_id`)
     OR NOT (NEW.`version` <=> OLD.`version`)
     OR NOT (NEW.`erstellt_am` <=> OLD.`erstellt_am`)
     OR NOT (BINARY NEW.`erstellt_von` <=> BINARY OLD.`erstellt_von`)
     OR NOT EXISTS (
       SELECT 1
         FROM `nv_einsatz_status` AS active_incident
         JOIN `nv_einsaetze` AS incident
           ON incident.`einsatz_id` = active_incident.`active_einsatz_id`
        WHERE active_incident.`singleton_id` = 1
          AND active_incident.`active_einsatz_id` = OLD.`einsatz_id`
          AND incident.`estab_status` = 'open'
     ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT =
        'Telecommunications plan update requires the active open incident';
  END IF;

  IF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ENTWURF' THEN
    IF OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NOT NULL
       OR NEW.`freigegeben_von` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Draft telecommunications plan has invalid release evidence';
    END IF;
  ELSEIF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'AKTIV' THEN
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NULL
       OR NEW.`freigegeben_von` IS NULL
       OR OLD.`gueltig_ab` > CURRENT_TIMESTAMP
       OR (OLD.`gueltig_bis` IS NOT NULL
           AND OLD.`gueltig_bis` < CURRENT_TIMESTAMP)
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_benutzer` AS release_account
           JOIN `nv_einsaetze` AS incident
             ON incident.`einsatz_id` = OLD.`einsatz_id`
          WHERE BINARY release_account.`kuerzel` =
                BINARY NEW.`freigegeben_von`
            AND release_account.`aktiv` = 1
            AND release_account.`estab_gesperrt` = 0
            AND (
              BINARY incident.`estab_permission_mode` = BINARY 'LOOSE'
              OR (
                BINARY release_account.`funktion` = BINARY 'S6'
                AND BINARY release_account.`rolle` = BINARY 'Stab'
              )
            )
       )
       OR NOT EXISTS (
         SELECT 1
           FROM `nv_fernmeldeplan_eintraege` AS release_entry
          WHERE release_entry.`fernmeldeplan_id` =
                OLD.`fernmeldeplan_id`
       ) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Telecommunications plan release account is invalid';
    END IF;
  ELSEIF OLD.`status` = 'ENTWURF' AND NEW.`status` = 'ERSETZT' THEN
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR OLD.`freigegeben_am` IS NOT NULL
       OR OLD.`freigegeben_von` IS NOT NULL
       OR NEW.`freigegeben_am` IS NOT NULL
       OR NEW.`freigegeben_von` IS NOT NULL THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT =
          'Discarded telecommunications drafts are immutable evidence';
    END IF;
  ELSEIF OLD.`status` = 'AKTIV' AND NEW.`status` = 'ERSETZT' THEN
    IF NOT (BINARY OLD.`einsatzbezeichnung` <=>
          BINARY NEW.`einsatzbezeichnung`)
       OR NOT (BINARY OLD.`herkunft` <=> BINARY NEW.`herkunft`)
       OR NOT (OLD.`gueltig_ab` <=> NEW.`gueltig_ab`)
       OR NOT (OLD.`gueltig_bis` <=> NEW.`gueltig_bis`)
       OR NOT (BINARY OLD.`betriebsleitung` <=>
          BINARY NEW.`betriebsleitung`)
       OR NOT (BINARY OLD.`bemerkungen` <=> BINARY NEW.`bemerkungen`)
       OR NOT (OLD.`freigegeben_am` <=> NEW.`freigegeben_am`)
       OR NOT (BINARY OLD.`freigegeben_von` <=>
          BINARY NEW.`freigegeben_von`) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Activated telecommunications plans are immutable';
    END IF;
  ELSE
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Invalid telecommunications plan status transition';
  END IF;
END//
DELIMITER ;
