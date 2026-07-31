-- eStab 0.9.26c database baseline for MariaDB 11.8.
--
-- The official MariaDB image selects MARIADB_DATABASE before executing files
-- in /docker-entrypoint-initdb.d.  This script deliberately does not create a
-- database, users, or grants.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_nachrichten` (
  `00_lfd` BIGINT NOT NULL AUTO_INCREMENT,
  `01_medium` SET('Fe','Fu','Me','FAX','FS','@') NOT NULL DEFAULT '',
  `01_datum` DATETIME NULL DEFAULT NULL,
  `01_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  `02_zeit` DATETIME NULL DEFAULT NULL,
  `02_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  `03_datum` DATETIME NULL DEFAULT NULL,
  `03_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  `04_richtung` SET('E','A') NOT NULL DEFAULT '',
  `04_nummer` BIGINT NOT NULL DEFAULT 0,
  `05_gegenstelle` VARCHAR(128) NOT NULL DEFAULT '',
  `06_befweg` VARCHAR(128) NOT NULL DEFAULT '',
  `06_befwegausw` SET('Fe','Fu','Me','FAX','FS','@') NOT NULL DEFAULT '',
  `07_durchspruch` SET('D','S') NOT NULL DEFAULT '',
  `08_befhinweis` VARCHAR(128) NOT NULL DEFAULT '',
  `08_befhinwausw` SET('Fe','Fu','Me','FAX','FS','@') NOT NULL DEFAULT '',
  `09_vorrangstufe` SET('eee','sss','bbb','aaa') NOT NULL DEFAULT '',
  `10_anschrift` VARCHAR(255) NOT NULL DEFAULT '',
  `11_rufnummer` VARCHAR(128) NOT NULL DEFAULT ''
    COMMENT 'estab:migration:98:message-counterparty-number:v1',
  `11_gesprnotiz` BINARY(1) NOT NULL DEFAULT 'f',
  `12_betreff` VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'estab:migration:98:message-subject:v1',
  `12_anhang` TEXT NULL,
  `12_inhalt` LONGTEXT NOT NULL,
  `12_abfzeit` DATETIME NULL DEFAULT NULL,
  `13_abseinheit` VARCHAR(128) NOT NULL DEFAULT '',
  `14_zeichen` VARCHAR(6) NOT NULL DEFAULT '',
  `14_funktion` VARCHAR(128) NOT NULL DEFAULT '',
  `15_quitdatum` DATETIME NULL DEFAULT NULL,
  `15_quitzeichen` VARCHAR(6) NOT NULL DEFAULT '',
  `16_empf` TINYTEXT NULL,
  `17_vermerke` LONGTEXT NULL,
  `20_master_katego` BIGINT NULL,
  `x00_status` SMALLINT NOT NULL DEFAULT 0,
  `x01_abschluss` BINARY(1) NOT NULL DEFAULT 'f',
  `x02_sperre` BINARY(1) NOT NULL DEFAULT 'f',
  `x03_sperruser` VARCHAR(6) NOT NULL DEFAULT '',
  `x04_druck` BINARY(1) NOT NULL DEFAULT 'f',
  `x05_druck_d` DATETIME NULL DEFAULT NULL,
  `99_lstacc` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`00_lfd`),
  KEY `idx_nachrichten_richtung_nummer` (`04_richtung`, `04_nummer`),
  KEY `idx_nachrichten_status` (`x00_status`),
  FULLTEXT KEY `ft_nachrichten_inhalt` (`12_inhalt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_empfmtx` (
  `mtx_lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mtx_x` INT NOT NULL,
  `mtx_y` INT NOT NULL,
  `mtx_typ` SET('cb','t') NOT NULL DEFAULT '',
  `mtx_fkt` VARCHAR(6) NOT NULL DEFAULT '',
  `mtx_rolle` SET('Stab','FB') NOT NULL DEFAULT '',
  `mtx_mode` SET('ro','rw') NOT NULL DEFAULT '',
  `mtx_rc2` BINARY(1) NOT NULL DEFAULT 'f',
  `mtx_auto` BINARY(1) NOT NULL DEFAULT 'f',
  PRIMARY KEY (`mtx_lfd`),
  UNIQUE KEY `uq_empfmtx_position` (`mtx_x`, `mtx_y`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The editor and runtime address the matrix as five rows by four columns.
-- These values reproduce 0.9.26c's bundled deault.fkt.php so a fresh container
-- exposes every historic function immediately. Existing cells are never
-- overwritten; INSERT IGNORE only supplies missing positions.
INSERT IGNORE INTO `nv_empfmtx`
  (`mtx_x`, `mtx_y`, `mtx_typ`, `mtx_fkt`, `mtx_rolle`, `mtx_mode`, `mtx_rc2`, `mtx_auto`)
VALUES
  (1,1,'cb','LS', 'Stab','ro','f','f'), (1,2,'cb','S5', 'Stab','ro','f','f'),
  (1,3,'t', '',   '',    'ro','f','f'), (1,4,'t', '',   '',    'ro','f','f'),
  (2,1,'cb','S1', 'Stab','ro','f','f'), (2,2,'cb','S6', 'Stab','ro','f','f'),
  (2,3,'t', '',   '',    'ro','f','f'), (2,4,'t', '',   '',    'ro','f','f'),
  (3,1,'cb','S2', 'Stab','ro','t','f'), (3,2,'cb','POL','FB',  'ro','f','f'),
  (3,3,'t', '',   '',    'ro','f','f'), (3,4,'t', '',   '',    'ro','f','f'),
  (4,1,'cb','S3', 'Stab','ro','f','f'), (4,2,'cb','THW','FB',  'ro','f','f'),
  (4,3,'t', '',   '',    'ro','f','f'), (4,4,'t', '',   '',    'ro','f','f'),
  (5,1,'cb','S4', 'Stab','ro','f','f'), (5,2,'cb','SAN','FB',  'ro','f','f'),
  (5,3,'t', '',   '',    'ro','f','f'), (5,4,'t', '',   '',    'ro','f','f');

CREATE TABLE IF NOT EXISTS `nv_benutzer` (
  `benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `funktion` VARCHAR(10) NOT NULL DEFAULT '',
  `rolle` VARCHAR(15) NOT NULL DEFAULT '',
  `sid` VARCHAR(50) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `fwdip` VARCHAR(45) NOT NULL DEFAULT '',
  `aktiv` SMALLINT NOT NULL DEFAULT 0,
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`kuerzel`),
  KEY `idx_benutzer_funktion_aktiv` (`funktion`, `aktiv`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CREATE TABLE IF NOT EXISTS does not widen an already provisioned legacy
-- column. Repeating this MODIFY is safe and leaves room for password_hash().
ALTER TABLE `nv_benutzer`
  MODIFY COLUMN `password` VARCHAR(255) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS `nv_masterkatego` (
  `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Laufende Nummer',
  `kategorie` VARCHAR(10) NOT NULL COMMENT 'Benutzerdefinierte Kategorie',
  `beschreibung` VARCHAR(254) NULL COMMENT 'Beschreibung zur Kategorie',
  PRIMARY KEY (`lfd`),
  UNIQUE KEY `uq_masterkatego_kategorie` (`kategorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One master category per message is intentional legacy behaviour: all link
-- updates and deletes address a row by msg alone.
CREATE TABLE IF NOT EXISTS `nv_masterkategolink` (
  `msg` BIGINT NOT NULL,
  `katego` BIGINT NOT NULL,
  PRIMARY KEY (`msg`),
  KEY `idx_masterkategolink_katego` (`katego`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_protokoll` (
  `p_lfd` BIGINT NOT NULL AUTO_INCREMENT,
  `p_zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `p_was` VARCHAR(30) NOT NULL DEFAULT '',
  `p_ereignis` TEXT NOT NULL,
  PRIMARY KEY (`p_lfd`),
  KEY `idx_protokoll_zeit` (`p_zeit`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_anhang` (
  `lfd-nr` BIGINT NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL DEFAULT '',
  `fileext` VARCHAR(16) NOT NULL DEFAULT '',
  `org_filename` VARCHAR(255) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NOT NULL DEFAULT '',
  `md5hash` VARCHAR(32) NOT NULL DEFAULT '',
  `date` DATETIME NULL DEFAULT NULL,
  `kuerzel` VARCHAR(6) NULL DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `id` VARCHAR(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`lfd-nr`),
  UNIQUE KEY `uq_anhang_filename` (`filename`),
  KEY `idx_anhang_filename_status` (`filename`, `status`),
  KEY `idx_anhang_id` (`id`),
  KEY `idx_anhang_md5hash` (`md5hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep existing Docker volumes compatible with the six-character login code.
ALTER TABLE `nv_anhang`
  MODIFY COLUMN `kuerzel` VARCHAR(6) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `nv_etb` (
  `etb_lfd-nr` INT NOT NULL AUTO_INCREMENT,
  `etb_time` DATETIME NOT NULL,
  `etb_aktion` TEXT NOT NULL,
  `etb_bemerk` TEXT NOT NULL,
  `etb_benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `etb_kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `etb_funktion` VARCHAR(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`etb_lfd-nr`),
  KEY `idx_etb_time` (`etb_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_tbb` (
  `tbb_lfd-nr` INT NOT NULL AUTO_INCREMENT,
  `tbb_time` DATETIME NOT NULL,
  `tbb_aktion` TEXT NOT NULL,
  `tbb_bemerk` TEXT NOT NULL,
  `tbb_benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `tbb_kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `tbb_funktion` VARCHAR(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`tbb_lfd-nr`),
  KEY `idx_tbb_time` (`tbb_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_ubb` (
  `ubb_lfd-nr` INT NOT NULL AUTO_INCREMENT,
  `ubb_time` DATETIME NOT NULL,
  `ubb_wo` TEXT NOT NULL,
  `ubb_wervon` TEXT NOT NULL,
  `ubb_weran` TEXT NOT NULL,
  `ubb_was` TEXT NOT NULL,
  `ubb_sonst` TEXT NOT NULL,
  `ubb_benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `ubb_kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `ubb_funktion` VARCHAR(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`ubb_lfd-nr`),
  KEY `idx_ubb_time` (`ubb_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_komplan` (
  `lfd` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stelle` VARCHAR(255) NOT NULL,
  `orga` VARCHAR(15) NULL,
  `rufname` VARCHAR(40) NULL,
  `kanal4` VARCHAR(8) NULL,
  `kanal2` VARCHAR(7) NULL,
  `tel1` VARCHAR(20) NULL,
  `tel2` VARCHAR(20) NULL,
  `mobil1` VARCHAR(20) NULL,
  `mobil2` VARCHAR(20) NULL,
  `fax1` VARCHAR(20) NULL,
  `fax2` VARCHAR(20) NULL,
  `e-mail` VARCHAR(255) NULL,
  `ftphttp` VARCHAR(255) NULL,
  PRIMARY KEY (`lfd`),
  KEY `idx_komplan_stelle` (`stelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_bhp50` (
  `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `anhang` VARCHAR(7) NULL,
  `name` VARCHAR(20) NULL,
  `vorname` VARCHAR(20) NULL,
  `geschlecht` SET('m','w') NULL,
  `nation` VARCHAR(20) NULL,
  `gebdat` VARCHAR(10) NULL,
  `fundort` VARCHAR(30) NULL,
  `datum` VARCHAR(10) NULL,
  `sich1` SET('1','2','3','4','5') NULL,
  `sich1_arzt` VARCHAR(20) NULL,
  `sich1_zeit` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `sich2` SET('1','2','3','4','5') NULL,
  `sich2_arzt` VARCHAR(20) NULL,
  `sich2_zeit` TIMESTAMP NULL DEFAULT NULL,
  `sich3` SET('1','2','3','4','5') NULL,
  `sich3_arzt` VARCHAR(20) NULL,
  `sich3_zeit` TIMESTAMP NULL DEFAULT NULL,
  `sich4` SET('1','2','3','4','5') NULL,
  `sich4_arzt` VARCHAR(20) NULL,
  `sich4_zeit` TIMESTAMP NULL DEFAULT NULL,
  `diagnose` TEXT NULL,
  `trans_liegend` SET('t','f') NULL,
  `trans_sitzend` SET('t','f') NULL,
  `mit_arzt` SET('t','f') NULL,
  `isoliert` SET('t','f') NULL,
  `trans_mittel` VARCHAR(15) NULL,
  `trans_ziel` VARCHAR(15) NULL,
  `trans_start` TIMESTAMP NULL DEFAULT NULL,
  `trans_dauer` FLOAT NOT NULL DEFAULT 0,
  `sudi_wohnort` VARCHAR(30) NULL,
  `sudi_strasse` VARCHAR(30) NULL,
  `sudi_konfekt` VARCHAR(3) NULL,
  `sudi_verbleib` VARCHAR(30) NULL,
  `sudi_bemerk` TEXT NULL,
  PRIMARY KEY (`lfd-nr`),
  KEY `idx_bhp50_anhang` (`anhang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ETB/TBB create these lazily in the legacy PHP. Pre-creating them avoids a
-- MyISAM/implicit-charset table in an otherwise transactional schema.
CREATE TABLE IF NOT EXISTS `nv_etbtitel` (
  `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz` VARCHAR(255) NOT NULL,
  `ort` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`lfd-nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `nv_tbbtitel` (
  `lfd-nr` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `einsatz` VARCHAR(255) NOT NULL,
  `ort` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`lfd-nr`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
