SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';
SET NAMES utf8mb4;

CREATE TABLE `nv_nachrichten` (
  `00_lfd` BIGINT NOT NULL AUTO_INCREMENT,
  `01_datum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `01_zeichen` VARCHAR(3) NOT NULL DEFAULT '',
  `02_zeit` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `02_zeichen` VARCHAR(3) NOT NULL DEFAULT '',
  `03_datum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `03_zeichen` VARCHAR(3) NOT NULL DEFAULT '',
  `12_abfzeit` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `14_zeichen` VARCHAR(3) NOT NULL DEFAULT '',
  `15_quitdatum` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `15_quitzeichen` VARCHAR(3) NOT NULL DEFAULT '',
  `x03_sperruser` VARCHAR(3) NOT NULL DEFAULT '',
  `x05_druck_d` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `99_lstacc` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'
    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`00_lfd`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_benutzer` (
  `benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `kuerzel` VARCHAR(3) NOT NULL DEFAULT '',
  `funktion` VARCHAR(10) NOT NULL DEFAULT '',
  `rolle` VARCHAR(15) NOT NULL DEFAULT '',
  `sid` VARCHAR(50) NOT NULL DEFAULT '',
  `ip` VARCHAR(15) NOT NULL DEFAULT '',
  `fwdip` VARCHAR(15) NOT NULL DEFAULT '',
  `aktiv` SMALLINT NOT NULL DEFAULT 0,
  `password` VARCHAR(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`kuerzel`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_anhang` (
  `lfd-nr` BIGINT NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `fileext` VARCHAR(3) NOT NULL DEFAULT '',
  `org_filename` VARCHAR(255) NOT NULL DEFAULT '',
  `comment` VARCHAR(255) NOT NULL DEFAULT '',
  `md5hash` VARCHAR(32) NOT NULL DEFAULT '',
  `date` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `kuerzel` VARCHAR(3) NULL DEFAULT NULL,
  `status` TINYINT NOT NULL DEFAULT 1,
  `id` VARCHAR(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`lfd-nr`),
  KEY `uq_anhang_filename` (`filename`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_bhp50` (
  `lfd-nr` BIGINT NOT NULL AUTO_INCREMENT,
  `sich1_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00'
    ON UPDATE CURRENT_TIMESTAMP,
  `sich2_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sich3_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `sich4_zeit` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  `trans_start` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`lfd-nr`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- Representative data-dependent table. Historical installations created
-- several of these per user/function; the schema migrator must discover them
-- without knowing their suffixes in advance.
CREATE TABLE `nv_legacy_read` (
  `lfd` BIGINT NOT NULL AUTO_INCREMENT,
  `msg` BIGINT NOT NULL,
  `notiz` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`lfd`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `nv_nachrichten`
  (`01_zeichen`, `02_zeichen`, `03_zeichen`, `14_zeichen`,
   `15_quitzeichen`, `x03_sperruser`)
VALUES ('S1', 'S2', 'S3', 'EL', 'LS', 'S4');

INSERT INTO `nv_benutzer`
  (`benutzer`, `kuerzel`, `funktion`, `rolle`, `ip`, `fwdip`, `aktiv`, `password`)
VALUES
  ('Legacy User', 'abc', 'S1', 'Stab', '192.0.2.10', '198.51.100.2', 0, 'legacy-secret');

INSERT INTO `nv_anhang`
  (`filename`, `fileext`, `org_filename`, `comment`, `md5hash`, `kuerzel`, `status`, `id`)
VALUES
  ('EL0001', 'pdf', 'lage-a.pdf', 'first', REPEAT('a', 32), 'abc', 1, 'old-session-a'),
  ('EL0001', 'pdf', 'lage-b.pdf', 'duplicate', REPEAT('b', 32), 'abc', 1, 'old-session-b');

INSERT INTO `nv_bhp50` (`lfd-nr`) VALUES (1);

INSERT INTO `nv_legacy_read` (`msg`, `notiz`)
VALUES (17, 'Müller erhält Größe');
