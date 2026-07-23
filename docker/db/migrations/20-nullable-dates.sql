-- Convert legacy MySQL zero dates to the NULL representation used by the
-- strict MariaDB schema. Back up the database before applying this script.
-- It is idempotent and preserves every non-zero date value.

SET @estab_previous_sql_mode := @@SESSION.sql_mode;
SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';

-- Columns must become nullable before zero values can be replaced by NULL.
ALTER TABLE `nv_nachrichten`
  MODIFY COLUMN `01_datum` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `02_zeit` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `03_datum` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `12_abfzeit` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `15_quitdatum` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `x05_druck_d` DATETIME NULL DEFAULT NULL,
  MODIFY COLUMN `99_lstacc` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

UPDATE `nv_nachrichten`
   SET `01_datum` = NULLIF(`01_datum`, '0000-00-00 00:00:00'),
       `02_zeit` = NULLIF(`02_zeit`, '0000-00-00 00:00:00'),
       `03_datum` = NULLIF(`03_datum`, '0000-00-00 00:00:00'),
       `12_abfzeit` = NULLIF(`12_abfzeit`, '0000-00-00 00:00:00'),
       `15_quitdatum` = NULLIF(`15_quitdatum`, '0000-00-00 00:00:00'),
       `x05_druck_d` = NULLIF(`x05_druck_d`, '0000-00-00 00:00:00'),
       -- Explicit assignment prevents ON UPDATE from replacing the historic
       -- last-access value merely because another date is being migrated.
       `99_lstacc` = NULLIF(`99_lstacc`, '0000-00-00 00:00:00');

ALTER TABLE `nv_anhang`
  MODIFY COLUMN `date` DATETIME NULL DEFAULT NULL;

UPDATE `nv_anhang`
   SET `date` = NULLIF(`date`, '0000-00-00 00:00:00');

ALTER TABLE `nv_bhp50`
  MODIFY COLUMN `sich1_zeit` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  MODIFY COLUMN `sich2_zeit` TIMESTAMP NULL DEFAULT NULL,
  MODIFY COLUMN `sich3_zeit` TIMESTAMP NULL DEFAULT NULL,
  MODIFY COLUMN `sich4_zeit` TIMESTAMP NULL DEFAULT NULL,
  MODIFY COLUMN `trans_start` TIMESTAMP NULL DEFAULT NULL;

UPDATE `nv_bhp50`
   SET `sich1_zeit` = NULLIF(`sich1_zeit`, '0000-00-00 00:00:00'),
       `sich2_zeit` = NULLIF(`sich2_zeit`, '0000-00-00 00:00:00'),
       `sich3_zeit` = NULLIF(`sich3_zeit`, '0000-00-00 00:00:00'),
       `sich4_zeit` = NULLIF(`sich4_zeit`, '0000-00-00 00:00:00'),
       `trans_start` = NULLIF(`trans_start`, '0000-00-00 00:00:00');

SET SESSION sql_mode = @estab_previous_sql_mode;
