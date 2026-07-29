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

-- A legitimate legacy installation contains all operational base tables.
-- Keep these representative definitions in the fixture so later migrations
-- can prove their cross-domain constraints without treating a damaged,
-- incomplete namespace as a valid source system.
CREATE TABLE `nv_protokoll` (
  `p_lfd` BIGINT NOT NULL AUTO_INCREMENT,
  `p_zeit` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `p_was` VARCHAR(30) NOT NULL DEFAULT '',
  `p_ereignis` TEXT NOT NULL,
  PRIMARY KEY (`p_lfd`),
  KEY `idx_protokoll_zeit` (`p_zeit`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_etb` (
  `etb_lfd-nr` INT NOT NULL AUTO_INCREMENT,
  `etb_time` DATETIME NOT NULL,
  `etb_aktion` TEXT NOT NULL,
  `etb_bemerk` TEXT NOT NULL,
  `etb_benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `etb_kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `etb_funktion` VARCHAR(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`etb_lfd-nr`),
  KEY `idx_etb_time` (`etb_time`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_tbb` (
  `tbb_lfd-nr` INT NOT NULL AUTO_INCREMENT,
  `tbb_time` DATETIME NOT NULL,
  `tbb_aktion` TEXT NOT NULL,
  `tbb_bemerk` TEXT NOT NULL,
  `tbb_benutzer` VARCHAR(50) NOT NULL DEFAULT '',
  `tbb_kuerzel` VARCHAR(6) NOT NULL DEFAULT '',
  `tbb_funktion` VARCHAR(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`tbb_lfd-nr`),
  KEY `idx_tbb_time` (`tbb_time`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_ubb` (
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
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

CREATE TABLE `nv_komplan` (
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

INSERT INTO `nv_nachrichten`
  (`01_datum`, `01_zeichen`, `02_zeichen`, `03_zeichen`, `14_zeichen`,
   `15_quitzeichen`, `x03_sperruser`, `99_lstacc`)
VALUES
  ('2019-01-02 03:04:05', 'S1', 'S2', 'S3', 'EL', 'LS', 'S4',
   '2019-02-03 04:05:06');

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

INSERT INTO `nv_bhp50`
  (`lfd-nr`, `sich1_zeit`, `sich2_zeit`, `sich3_zeit`,
   `sich4_zeit`, `trans_start`)
VALUES
  (2, '2018-01-02 03:04:05', '2018-02-03 04:05:06',
   '2018-03-04 05:06:07', '2018-04-05 06:07:08',
   '2018-05-06 07:08:09');

INSERT INTO `nv_legacy_read` (`msg`, `notiz`)
VALUES (17, 'Müller erhält Größe');
