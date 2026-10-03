-- Reinigungssoftware: komplette Datenbank in einem Schritt.
-- In phpMyAdmin: Datenbank auswählen → Reiter Importieren → diese Datei.
-- Erstellt 13.09.2026
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===== schema_v2.sql =====
-- =====================================================================
-- Reinigungs-SaaS — Datenbankschema v2
-- Ziel: MySQL 5.7 (easyname Shared Hosting), InnoDB, utf8mb4
-- Ausführen in einer LEEREN Datenbank. Keine ALTER-Befehle enthalten.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- =====================================================================
-- 1. MANDANT UND BENUTZER
-- =====================================================================

-- Der Mandant: ein Reinigungsbetrieb
CREATE TABLE betriebe (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                     VARCHAR(150) NOT NULL,
  slug                     VARCHAR(60)  NOT NULL,                 -- URL-Kennung, eindeutig
  uid_nummer               VARCHAR(20)  DEFAULT NULL,
  adresse                  VARCHAR(150) DEFAULT NULL,
  plz                      VARCHAR(10)  DEFAULT NULL,
  ort                      VARCHAR(80)  DEFAULT NULL,
  telefon                  VARCHAR(40)  DEFAULT NULL,
  email                    VARCHAR(150) DEFAULT NULL,
  logo_pfad                VARCHAR(255) DEFAULT NULL,
  farbe_primaer            CHAR(7)      DEFAULT NULL,             -- #RRGGBB
  farbe_sekundaer          CHAR(7)      DEFAULT NULL,
  tarif                    ENUM('basis','plus','pro') NOT NULL DEFAULT 'basis',
  max_mitarbeiter          SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  status                   ENUM('test','aktiv','gesperrt','gekuendigt') NOT NULL DEFAULT 'test',
  gekuendigt_am            DATE         DEFAULT NULL,
  geloescht_am             DATETIME     DEFAULT NULL,
  testphase_bis            DATE         DEFAULT NULL,
  av_vertrag_akzeptiert_am DATETIME     DEFAULT NULL,
  erstellt_am              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_betriebe_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Ein Login-System für alle Rollen
CREATE TABLE benutzer (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  rolle           ENUM('inhaber','office','mitarbeiter','kunde') NOT NULL,
  benutzername    VARCHAR(80)  NOT NULL,                          -- Personalnummer bei Mitarbeitern
  email           VARCHAR(150) DEFAULT NULL,
  passwort_hash   VARCHAR(255) DEFAULT NULL,                      -- inhaber/office/kunde
  pin_hash        VARCHAR(255) DEFAULT NULL,                      -- mitarbeiter (4-stellig, gehasht)
  mitarbeiter_id  INT UNSIGNED DEFAULT NULL,
  kunde_id        INT UNSIGNED DEFAULT NULL,
  aktiv           TINYINT(1)   NOT NULL DEFAULT 1,
  letzter_login   DATETIME     DEFAULT NULL,
  fehlversuche    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  gesperrt_bis    DATETIME     DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_benutzer_betrieb_name (betrieb_id, benutzername),
  KEY ix_benutzer_email (email),
  KEY ix_benutzer_mitarbeiter (mitarbeiter_id),
  KEY ix_benutzer_kunde (kunde_id),
  CONSTRAINT fk_benutzer_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Sitzungen der Apps (Token, nicht Cookie)
CREATE TABLE api_tokens (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  benutzer_id     INT UNSIGNED NOT NULL,
  token_hash      CHAR(64)     NOT NULL,                          -- SHA-256 des Tokens
  geraet          VARCHAR(150) DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab       DATETIME     NOT NULL,
  letzter_zugriff DATETIME     DEFAULT NULL,
  widerrufen      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY ix_api_tokens_benutzer (benutzer_id),
  CONSTRAINT fk_api_tokens_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id),
  CONSTRAINT fk_api_tokens_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Fehlgeschlagene und erfolgreiche Logins (Sperrlogik)
CREATE TABLE loginversuche (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id   INT UNSIGNED DEFAULT NULL,                         -- NULL, wenn Betrieb nicht ermittelbar
  benutzername VARCHAR(80)  NOT NULL,
  ip_adresse   VARCHAR(45)  NOT NULL,
  erfolgreich  TINYINT(1)   NOT NULL DEFAULT 0,
  zeitpunkt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_loginversuche_name_zeit (benutzername, zeitpunkt),
  KEY ix_loginversuche_ip_zeit (ip_adresse, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 2. PERSONAL
-- =====================================================================

CREATE TABLE mitarbeiter (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id            INT UNSIGNED NOT NULL,
  personalnummer        VARCHAR(20)  NOT NULL,
  vorname               VARCHAR(80)  NOT NULL,
  nachname              VARCHAR(80)  NOT NULL,
  telefon               VARCHAR(40)  DEFAULT NULL,
  email                 VARCHAR(150) DEFAULT NULL,
  adresse               VARCHAR(150) DEFAULT NULL,
  plz                   VARCHAR(10)  DEFAULT NULL,
  ort                   VARCHAR(80)  DEFAULT NULL,
  geburtsdatum          DATE         DEFAULT NULL,
  eintrittsdatum        DATE         DEFAULT NULL,
  austrittsdatum        DATE         DEFAULT NULL,
  austrittsgrund        VARCHAR(255) DEFAULT NULL,
  anstellung            ENUM('geringfuegig','teilzeit','vollzeit') NOT NULL DEFAULT 'teilzeit',
  stunden_woche         DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  arbeitstage_woche     TINYINT UNSIGNED NOT NULL DEFAULT 5,
  lohngruppe            VARCHAR(10)  DEFAULT NULL,                -- KV-Lohngruppe
  stundenlohn           DECIMAL(8,2) DEFAULT NULL,                -- Brutto-Stundenlohn
  verrechnungssatz      DECIMAL(8,2) DEFAULT NULL,                -- optional: Satz an den Kunden je Mitarbeiter
  urlaubstage_jahr      DECIMAL(4,1) NOT NULL DEFAULT 25.0,
  dienstjahre_vorher    DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  fuehrerschein         TINYINT(1)   NOT NULL DEFAULT 0,
  hat_auto              TINYINT(1)   NOT NULL DEFAULT 0,
  notfallkontakt_name   VARCHAR(120) DEFAULT NULL,
  notfallkontakt_telefon VARCHAR(40) DEFAULT NULL,
  notizen               TEXT         DEFAULT NULL,
  aktiv                 TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt_am           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mitarbeiter_betrieb_pnr (betrieb_id, personalnummer),
  KEY ix_mitarbeiter_betrieb_aktiv (betrieb_id, aktiv),
  CONSTRAINT fk_mitarbeiter_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Verschlüsselte Felder, Zugriff nur mit Lohnrecht
CREATE TABLE mitarbeiter_sensibel (
  mitarbeiter_id  INT UNSIGNED NOT NULL,
  betrieb_id      INT UNSIGNED NOT NULL,
  svnr_enc        VARBINARY(255) DEFAULT NULL,
  iban_enc        VARBINARY(255) DEFAULT NULL,
  anzahl_kinder   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  aktualisiert_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (mitarbeiter_id),
  KEY ix_mitarbeiter_sensibel_betrieb (betrieb_id),
  CONSTRAINT fk_ma_sensibel_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE,
  CONSTRAINT fk_ma_sensibel_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Regelmäßige Verfügbarkeit (Basis für Ersatzsuche)
CREATE TABLE mitarbeiter_verfuegbarkeit (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id     INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  wochentag      TINYINT UNSIGNED NOT NULL,                       -- 1=Mo … 7=So
  uhrzeit_von    TIME         NOT NULL,
  uhrzeit_bis    TIME         NOT NULL,
  PRIMARY KEY (id),
  KEY ix_ma_verf_mitarbeiter_tag (mitarbeiter_id, wochentag),
  CONSTRAINT fk_ma_verf_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_ma_verf_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Urlaub, Krankheit, Zeitausgleich — ohne Grund-Feld
CREATE TABLE abwesenheiten (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  mitarbeiter_id  INT UNSIGNED NOT NULL,
  typ             ENUM('urlaub','krank','zeitausgleich','einschulung','sonstiges') NOT NULL,
  datum_von       DATE         NOT NULL,
  datum_bis       DATE         NOT NULL,
  tage            DECIMAL(4,1) NOT NULL DEFAULT 1.0,
  status          ENUM('beantragt','genehmigt','abgelehnt') NOT NULL DEFAULT 'beantragt',
  genehmigt_von   INT UNSIGNED DEFAULT NULL,                      -- benutzer.id
  genehmigt_am    DATETIME     DEFAULT NULL,
  kommentar_office VARCHAR(255) DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_abwesenheiten_ma_zeitraum (mitarbeiter_id, datum_von, datum_bis),
  KEY ix_abwesenheiten_betrieb_zeitraum (betrieb_id, datum_von, datum_bis),
  CONSTRAINT fk_abwesenheiten_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_abwesenheiten_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id),
  CONSTRAINT fk_abwesenheiten_genehmiger  FOREIGN KEY (genehmigt_von)  REFERENCES benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Monatsauswertung je Mitarbeiter, mit Unterschrift
CREATE TABLE stundenkonto (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id         INT UNSIGNED NOT NULL,
  mitarbeiter_id     INT UNSIGNED NOT NULL,
  jahr               SMALLINT UNSIGNED NOT NULL,
  monat              TINYINT UNSIGNED NOT NULL,
  soll_minuten       INT          NOT NULL DEFAULT 0,
  ist_minuten        INT          NOT NULL DEFAULT 0,
  differenz_minuten  INT          NOT NULL DEFAULT 0,
  pdf_pfad           VARCHAR(255) DEFAULT NULL,
  erstellt_am        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unterschrieben_am  DATETIME     DEFAULT NULL,
  unterschrift_pfad  VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stundenkonto_ma_monat (mitarbeiter_id, jahr, monat),
  KEY ix_stundenkonto_betrieb (betrieb_id, jahr, monat),
  CONSTRAINT fk_stundenkonto_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_stundenkonto_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Mitarbeiter beantragt Änderung eigener Stammdaten
CREATE TABLE aenderungsanfragen (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id     INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  feld           VARCHAR(50)  NOT NULL,
  alter_wert     VARCHAR(255) DEFAULT NULL,
  neuer_wert     VARCHAR(255) DEFAULT NULL,
  status         ENUM('offen','uebernommen','abgelehnt') NOT NULL DEFAULT 'offen',
  bearbeitet_von INT UNSIGNED DEFAULT NULL,
  bearbeitet_am  DATETIME     DEFAULT NULL,
  kommentar      VARCHAR(255) DEFAULT NULL,
  erstellt_am    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_aenderungsanfragen_betrieb_status (betrieb_id, status),
  CONSTRAINT fk_aenderungsanfragen_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_aenderungsanfragen_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE,
  CONSTRAINT fk_aenderungsanfragen_bearbeiter  FOREIGN KEY (bearbeitet_von) REFERENCES benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 3. KUNDEN UND OBJEKTE
-- =====================================================================

CREATE TABLE kunden (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id        INT UNSIGNED NOT NULL,
  firmenname        VARCHAR(150) NOT NULL,
  uid_nummer        VARCHAR(20)  DEFAULT NULL,
  adresse           VARCHAR(150) DEFAULT NULL,
  plz               VARCHAR(10)  DEFAULT NULL,
  ort               VARCHAR(80)  DEFAULT NULL,
  bezirk            VARCHAR(40)  DEFAULT NULL,
  kontaktperson     VARCHAR(120) DEFAULT NULL,
  telefon           VARCHAR(40)  DEFAULT NULL,
  email             VARCHAR(150) DEFAULT NULL,
  rechnungs_email   VARCHAR(150) DEFAULT NULL,
  rechnungsadresse  VARCHAR(255) DEFAULT NULL,                    -- nur wenn abweichend
  zahlungsziel_tage TINYINT UNSIGNED NOT NULL DEFAULT 14,
  quelle            VARCHAR(50)  DEFAULT NULL,
  status            ENUM('interessent','aktiv','inaktiv') NOT NULL DEFAULT 'interessent',
  notizen           TEXT         DEFAULT NULL,
  erstellt_am       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_kunden_betrieb_status (betrieb_id, status),
  KEY ix_kunden_betrieb_name (betrieb_id, firmenname),
  CONSTRAINT fk_kunden_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE kunden_notizen (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id  INT UNSIGNED NOT NULL,
  kunde_id    INT UNSIGNED NOT NULL,
  benutzer_id INT UNSIGNED DEFAULT NULL,
  notiz       TEXT         NOT NULL,
  erstellt_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_kunden_notizen_kunde (kunde_id, erstellt_am),
  CONSTRAINT fk_kunden_notizen_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id),
  CONSTRAINT fk_kunden_notizen_kunde    FOREIGN KEY (kunde_id)    REFERENCES kunden (id) ON DELETE CASCADE,
  CONSTRAINT fk_kunden_notizen_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE objekte (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id         INT UNSIGNED NOT NULL,
  kunde_id           INT UNSIGNED NOT NULL,
  name               VARCHAR(150) NOT NULL,
  adresse            VARCHAR(150) NOT NULL,
  plz                VARCHAR(10)  NOT NULL,
  ort                VARCHAR(80)  NOT NULL,
  latitude           DECIMAL(10,7) DEFAULT NULL,                  -- nur für Kartenanzeige im Office
  longitude          DECIMAL(10,7) DEFAULT NULL,
  ansprechpartner    VARCHAR(120) DEFAULT NULL,
  telefon            VARCHAR(40)  DEFAULT NULL,
  email              VARCHAR(150) DEFAULT NULL,
  zugang_info        TEXT         DEFAULT NULL,                   -- unkritische Hinweise (Stiege, Tür)
  reinigung_hinweise TEXT         DEFAULT NULL,
  qr_token           CHAR(32)     NOT NULL,                       -- Check-in-Token, zufällig
  nfc_token          CHAR(32)     DEFAULT NULL,
  aktiv              TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt_am        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_objekte_qr (qr_token),
  UNIQUE KEY uq_objekte_nfc (nfc_token),
  KEY ix_objekte_betrieb_aktiv (betrieb_id, aktiv),
  KEY ix_objekte_kunde (kunde_id),
  CONSTRAINT fk_objekte_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_objekte_kunde   FOREIGN KEY (kunde_id)   REFERENCES kunden (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Verschlüsselte Zugangsdaten zum Objekt
CREATE TABLE objekt_zugang (
  objekt_id           INT UNSIGNED NOT NULL,
  betrieb_id          INT UNSIGNED NOT NULL,
  schluessel_info_enc VARBINARY(1024) DEFAULT NULL,
  alarm_code_enc      VARBINARY(255)  DEFAULT NULL,
  aktualisiert_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (objekt_id),
  KEY ix_objekt_zugang_betrieb (betrieb_id),
  CONSTRAINT fk_objekt_zugang_objekt  FOREIGN KEY (objekt_id)  REFERENCES objekte (id) ON DELETE CASCADE,
  CONSTRAINT fk_objekt_zugang_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Leistungskatalog je Betrieb
CREATE TABLE leistungsarten (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id              INT UNSIGNED NOT NULL,
  name                    VARCHAR(100) NOT NULL,
  kategorie               ENUM('unterhalt','grund','fenster','sonder','regie') NOT NULL DEFAULT 'unterhalt',
  standard_stundensatz    DECIMAL(8,2) DEFAULT NULL,
  standard_dauer_minuten  SMALLINT UNSIGNED DEFAULT NULL,
  aktiv                   TINYINT(1)   NOT NULL DEFAULT 1,
  sortierung              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_leistungsarten_betrieb (betrieb_id, aktiv, sortierung),
  CONSTRAINT fk_leistungsarten_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Was an einem Objekt regelmäßig gemacht wird (Herzstück)
CREATE TABLE objekt_leistungen (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id          INT UNSIGNED NOT NULL,
  objekt_id           INT UNSIGNED NOT NULL,
  leistungsart_id     INT UNSIGNED NOT NULL,
  turnus              ENUM('woechentlich','14taegig','monatlich','einmalig') NOT NULL DEFAULT 'woechentlich',
  wochentage          TINYINT UNSIGNED NOT NULL DEFAULT 0,        -- Bitmaske: Mo=1, Di=2, Mi=4, Do=8, Fr=16, Sa=32, So=64
  uhrzeit_von         TIME         DEFAULT NULL,
  uhrzeit_bis         TIME         DEFAULT NULL,
  dauer_soll_minuten  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  stundensatz         DECIMAL(8,2) DEFAULT NULL,                  -- überschreibt leistungsarten.standard_stundensatz
  preis_monat         DECIMAL(10,2) DEFAULT NULL,                 -- Pauschale, wenn gesetzt
  preis_einheit       DECIMAL(10,2) DEFAULT NULL,                 -- Preis je Einsatz, wenn gesetzt
  gueltig_von         DATE         DEFAULT NULL,
  gueltig_bis         DATE         DEFAULT NULL,
  aktiv               TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt_am         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_objekt_leistungen_objekt (objekt_id, aktiv),
  KEY ix_objekt_leistungen_betrieb (betrieb_id, aktiv),
  CONSTRAINT fk_objekt_leistungen_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_objekt_leistungen_objekt       FOREIGN KEY (objekt_id)       REFERENCES objekte (id),
  CONSTRAINT fk_objekt_leistungen_leistungsart FOREIGN KEY (leistungsart_id) REFERENCES leistungsarten (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Stammzuordnung Mitarbeiter zu Objekt-Leistung
CREATE TABLE objekt_leistung_mitarbeiter (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id         INT UNSIGNED NOT NULL,
  objekt_leistung_id INT UNSIGNED NOT NULL,
  mitarbeiter_id     INT UNSIGNED NOT NULL,
  reihenfolge        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_olm (objekt_leistung_id, mitarbeiter_id),
  KEY ix_olm_mitarbeiter (mitarbeiter_id),
  CONSTRAINT fk_olm_betrieb         FOREIGN KEY (betrieb_id)         REFERENCES betriebe (id),
  CONSTRAINT fk_olm_objekt_leistung FOREIGN KEY (objekt_leistung_id) REFERENCES objekt_leistungen (id) ON DELETE CASCADE,
  CONSTRAINT fk_olm_mitarbeiter     FOREIGN KEY (mitarbeiter_id)     REFERENCES mitarbeiter (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 5. SUBUNTERNEHMER (vor Einsätzen, wegen Fremdschlüssel)
-- =====================================================================

CREATE TABLE subunternehmer (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  firmenname      VARCHAR(150) NOT NULL,
  ansprechpartner VARCHAR(120) DEFAULT NULL,
  email           VARCHAR(150) DEFAULT NULL,
  telefon         VARCHAR(40)  DEFAULT NULL,
  adresse         VARCHAR(150) DEFAULT NULL,
  plz             VARCHAR(10)  DEFAULT NULL,
  ort             VARCHAR(80)  DEFAULT NULL,
  uid_nummer      VARCHAR(20)  DEFAULT NULL,
  stundensatz     DECIMAL(8,2) DEFAULT NULL,                      -- Einkaufssatz
  notizen         TEXT         DEFAULT NULL,
  aktiv           TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_subunternehmer_betrieb (betrieb_id, aktiv),
  CONSTRAINT fk_subunternehmer_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE subunternehmer_objekte (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id           INT UNSIGNED NOT NULL,
  subunternehmer_id    INT UNSIGNED NOT NULL,
  objekt_leistung_id   INT UNSIGNED NOT NULL,
  stundensatz_override DECIMAL(8,2) DEFAULT NULL,
  aktiv                TINYINT(1)   NOT NULL DEFAULT 1,
  erstellt_am          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_objekte (subunternehmer_id, objekt_leistung_id),
  CONSTRAINT fk_sub_objekte_betrieb         FOREIGN KEY (betrieb_id)         REFERENCES betriebe (id),
  CONSTRAINT fk_sub_objekte_subunternehmer  FOREIGN KEY (subunternehmer_id)  REFERENCES subunternehmer (id),
  CONSTRAINT fk_sub_objekte_objekt_leistung FOREIGN KEY (objekt_leistung_id) REFERENCES objekt_leistungen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 4. EINSÄTZE UND ZEITERFASSUNG
-- =====================================================================

-- Ein Einsatz: entweder eigene Mitarbeiter (einsatz_mitarbeiter) oder ein Subunternehmer
CREATE TABLE einsaetze (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id                INT UNSIGNED NOT NULL,
  objekt_id                 INT UNSIGNED NOT NULL,
  objekt_leistung_id        INT UNSIGNED DEFAULT NULL,            -- NULL bei einmaligen/manuellen Einsätzen
  leistungsart_id           INT UNSIGNED NOT NULL,
  subunternehmer_id         INT UNSIGNED DEFAULT NULL,
  datum                     DATE         NOT NULL,
  uhrzeit_von               TIME         NOT NULL,
  uhrzeit_bis               TIME         NOT NULL,
  dauer_soll_minuten        SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  status                    ENUM('geplant','laeuft','erledigt','ausgefallen','verschoben','storniert') NOT NULL DEFAULT 'geplant',
  ersatz_fuer_einsatz_id    INT UNSIGNED DEFAULT NULL,
  verschoben_nach_einsatz_id INT UNSIGNED DEFAULT NULL,
  notizen_office            TEXT         DEFAULT NULL,
  notizen_mitarbeiter       TEXT         DEFAULT NULL,
  kunde_informiert_am       DATETIME     DEFAULT NULL,
  erstellt_am               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_einsaetze_betrieb_datum (betrieb_id, datum),
  KEY ix_einsaetze_objekt_datum (objekt_id, datum),
  KEY ix_einsaetze_status (betrieb_id, status, datum),
  KEY ix_einsaetze_objekt_leistung (objekt_leistung_id),
  KEY ix_einsaetze_subunternehmer (subunternehmer_id, datum),
  CONSTRAINT fk_einsaetze_betrieb         FOREIGN KEY (betrieb_id)         REFERENCES betriebe (id),
  CONSTRAINT fk_einsaetze_objekt          FOREIGN KEY (objekt_id)          REFERENCES objekte (id),
  CONSTRAINT fk_einsaetze_objekt_leistung FOREIGN KEY (objekt_leistung_id) REFERENCES objekt_leistungen (id) ON DELETE SET NULL,
  CONSTRAINT fk_einsaetze_leistungsart    FOREIGN KEY (leistungsart_id)    REFERENCES leistungsarten (id),
  CONSTRAINT fk_einsaetze_subunternehmer  FOREIGN KEY (subunternehmer_id)  REFERENCES subunternehmer (id),
  CONSTRAINT fk_einsaetze_ersatz          FOREIGN KEY (ersatz_fuer_einsatz_id)     REFERENCES einsaetze (id),
  CONSTRAINT fk_einsaetze_verschoben      FOREIGN KEY (verschoben_nach_einsatz_id) REFERENCES einsaetze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Mehrere Mitarbeiter pro Einsatz
CREATE TABLE einsatz_mitarbeiter (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id     INT UNSIGNED NOT NULL,
  einsatz_id     INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  ist_ersatz     TINYINT(1)   NOT NULL DEFAULT 0,
  erstellt_am    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_einsatz_mitarbeiter (einsatz_id, mitarbeiter_id),
  KEY ix_einsatz_mitarbeiter_ma (mitarbeiter_id),
  CONSTRAINT fk_em_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_em_einsatz     FOREIGN KEY (einsatz_id)     REFERENCES einsaetze (id) ON DELETE CASCADE,
  CONSTRAINT fk_em_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Arbeitszeitaufzeichnung nach § 26 AZG: ein Datensatz je Mitarbeiter je Einsatz
CREATE TABLE zeiterfassung (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id       INT UNSIGNED NOT NULL,
  einsatz_id       INT UNSIGNED NOT NULL,
  mitarbeiter_id   INT UNSIGNED NOT NULL,
  checkin_zeit     DATETIME     NOT NULL,
  checkin_methode  ENUM('qr','nfc','manuell') NOT NULL DEFAULT 'qr',
  checkout_zeit    DATETIME     DEFAULT NULL,
  checkout_methode ENUM('qr','nfc','manuell') DEFAULT NULL,
  pause_minuten    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  minuten_brutto   SMALLINT UNSIGNED DEFAULT NULL,                -- checkout - checkin
  minuten_netto    SMALLINT UNSIGNED DEFAULT NULL,                -- brutto - pause
  korrigiert_von   INT UNSIGNED DEFAULT NULL,                     -- benutzer.id
  korrektur_grund  VARCHAR(255) DEFAULT NULL,
  korrigiert_am    DATETIME     DEFAULT NULL,
  erstellt_am      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zeiterfassung_einsatz_ma (einsatz_id, mitarbeiter_id),
  KEY ix_zeiterfassung_ma_zeit (mitarbeiter_id, checkin_zeit),
  KEY ix_zeiterfassung_betrieb_zeit (betrieb_id, checkin_zeit),
  CONSTRAINT fk_zeiterfassung_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_zeiterfassung_einsatz     FOREIGN KEY (einsatz_id)     REFERENCES einsaetze (id),
  CONSTRAINT fk_zeiterfassung_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id),
  CONSTRAINT fk_zeiterfassung_korrektor   FOREIGN KEY (korrigiert_von) REFERENCES benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Fotos vor/nach; loeschen_am = aufgenommen_am + 3 Monate (fix), Cronjob löscht
CREATE TABLE einsatz_fotos (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id     INT UNSIGNED NOT NULL,
  einsatz_id     INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED DEFAULT NULL,
  typ            ENUM('vorher','nachher','schaden','sonstiges') NOT NULL DEFAULT 'nachher',
  pfad           VARCHAR(255) NOT NULL,
  dateigroesse   INT UNSIGNED DEFAULT NULL,
  aufgenommen_am DATETIME     NOT NULL,
  loeschen_am    DATE         NOT NULL,
  geloescht      TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_einsatz_fotos_einsatz (einsatz_id),
  KEY ix_einsatz_fotos_loeschen (geloescht, loeschen_am),
  CONSTRAINT fk_einsatz_fotos_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_einsatz_fotos_einsatz     FOREIGN KEY (einsatz_id)     REFERENCES einsaetze (id),
  CONSTRAINT fk_einsatz_fotos_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Leistungsnachweis mit Unterschrift des Kunden
CREATE TABLE einsatz_nachweise (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id           INT UNSIGNED NOT NULL,
  einsatz_id           INT UNSIGNED NOT NULL,
  bestaetigt_von_name  VARCHAR(120) DEFAULT NULL,
  unterschrift_pfad    VARCHAR(255) DEFAULT NULL,
  pdf_pfad             VARCHAR(255) DEFAULT NULL,
  bestaetigt_am        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_einsatz_nachweise_einsatz (einsatz_id),
  CONSTRAINT fk_einsatz_nachweise_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_einsatz_nachweise_einsatz FOREIGN KEY (einsatz_id) REFERENCES einsaetze (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE material (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id    INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL,
  einheit       VARCHAR(20)  NOT NULL DEFAULT 'Stk',
  einkaufspreis DECIMAL(8,2) DEFAULT NULL,
  verkaufspreis DECIMAL(8,2) DEFAULT NULL,
  aktiv         TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_material_betrieb (betrieb_id, aktiv),
  CONSTRAINT fk_material_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE einsatz_material (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id  INT UNSIGNED NOT NULL,
  einsatz_id  INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  menge       DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (id),
  KEY ix_einsatz_material_einsatz (einsatz_id),
  CONSTRAINT fk_einsatz_material_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id),
  CONSTRAINT fk_einsatz_material_einsatz  FOREIGN KEY (einsatz_id)  REFERENCES einsaetze (id) ON DELETE CASCADE,
  CONSTRAINT fk_einsatz_material_material FOREIGN KEY (material_id) REFERENCES material (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 7. ABRECHNUNG (vor Regieleistungen, wegen Fremdschlüssel)
-- =====================================================================

-- Fortlaufende Nummern je Betrieb, Typ und Jahr
CREATE TABLE nummernkreise (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id    INT UNSIGNED NOT NULL,
  typ           ENUM('rechnung','angebot','gutschrift') NOT NULL,
  jahr          SMALLINT UNSIGNED NOT NULL,
  praefix       VARCHAR(10)  NOT NULL DEFAULT 'RE',
  letzte_nummer INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nummernkreise (betrieb_id, typ, jahr),
  CONSTRAINT fk_nummernkreise_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE rechnungen (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id              INT UNSIGNED NOT NULL,
  kunde_id                INT UNSIGNED NOT NULL,
  rechnungsnummer         VARCHAR(30)  DEFAULT NULL,              -- wird beim Festschreiben vergeben
  rechnungsdatum          DATE         DEFAULT NULL,
  leistungszeitraum_von   DATE         DEFAULT NULL,
  leistungszeitraum_bis   DATE         DEFAULT NULL,
  faellig_am              DATE         DEFAULT NULL,
  netto                   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ust_satz                DECIMAL(4,2) NOT NULL DEFAULT 20.00,
  ust                     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  brutto                  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status                  ENUM('entwurf','gestellt','teilbezahlt','bezahlt','mahnung_1','mahnung_2','storniert') NOT NULL DEFAULT 'entwurf',
  bezahlt_am              DATE         DEFAULT NULL,
  bezahlt_betrag          DECIMAL(10,2) DEFAULT NULL,
  storno_von_rechnung_id  INT UNSIGNED DEFAULT NULL,              -- diese Rechnung storniert jene
  pdf_pfad                VARCHAR(255) DEFAULT NULL,
  festgeschrieben_am      DATETIME     DEFAULT NULL,              -- danach unveränderbar
  notizen                 TEXT         DEFAULT NULL,
  erstellt_am             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rechnungen_nummer (betrieb_id, rechnungsnummer),
  KEY ix_rechnungen_kunde (kunde_id),
  KEY ix_rechnungen_betrieb_status (betrieb_id, status),
  KEY ix_rechnungen_datum (betrieb_id, rechnungsdatum),
  CONSTRAINT fk_rechnungen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_rechnungen_kunde   FOREIGN KEY (kunde_id)   REFERENCES kunden (id),
  CONSTRAINT fk_rechnungen_storno  FOREIGN KEY (storno_von_rechnung_id) REFERENCES rechnungen (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE rechnung_positionen (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  rechnung_id     INT UNSIGNED NOT NULL,
  objekt_id       INT UNSIGNED DEFAULT NULL,
  leistungsart_id INT UNSIGNED DEFAULT NULL,
  beschreibung    VARCHAR(255) NOT NULL,
  menge           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  einheit         VARCHAR(20)  NOT NULL DEFAULT 'Pauschale',
  einzelpreis     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gesamtpreis     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  sortierung      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_rechnung_positionen_rechnung (rechnung_id, sortierung),
  CONSTRAINT fk_rp_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_rp_rechnung     FOREIGN KEY (rechnung_id)     REFERENCES rechnungen (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_objekt       FOREIGN KEY (objekt_id)       REFERENCES objekte (id),
  CONSTRAINT fk_rp_leistungsart FOREIGN KEY (leistungsart_id) REFERENCES leistungsarten (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Layout und Texte der Rechnung je Betrieb ("individuell anpassbar")
CREATE TABLE rechnungs_layout (
  betrieb_id              INT UNSIGNED NOT NULL,
  logo_pfad               VARCHAR(255) DEFAULT NULL,
  kopfzeile               TEXT         DEFAULT NULL,
  fusszeile               TEXT         DEFAULT NULL,
  farbe                   CHAR(7)      DEFAULT NULL,
  schriftart              VARCHAR(60)  DEFAULT NULL,
  einleitungstext         TEXT         DEFAULT NULL,
  schlusstext             TEXT         DEFAULT NULL,
  zahlungsbedingungen     TEXT         DEFAULT NULL,
  bankname                VARCHAR(100) DEFAULT NULL,
  iban                    VARCHAR(34)  DEFAULT NULL,              -- Betriebs-IBAN, steht ohnehin auf jeder Rechnung
  bic                     VARCHAR(11)  DEFAULT NULL,
  zeige_positionen_detail TINYINT(1)   NOT NULL DEFAULT 1,
  aktualisiert_am         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (betrieb_id),
  CONSTRAINT fk_rechnungs_layout_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Zusatzleistungen, Sonderreinigungen, Fahrtkosten
CREATE TABLE regieleistungen (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  kunde_id        INT UNSIGNED NOT NULL,
  objekt_id       INT UNSIGNED DEFAULT NULL,
  einsatz_id      INT UNSIGNED DEFAULT NULL,
  leistungsart_id INT UNSIGNED DEFAULT NULL,
  datum           DATE         NOT NULL,
  beschreibung    VARCHAR(255) NOT NULL,
  menge           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  einheit         VARCHAR(20)  NOT NULL DEFAULT 'Std',
  einzelpreis     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  fahrtkosten     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  rechnung_id     INT UNSIGNED DEFAULT NULL,                      -- NULL = noch nicht abgerechnet
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_regieleistungen_offen (betrieb_id, rechnung_id, datum),
  KEY ix_regieleistungen_kunde (kunde_id, datum),
  CONSTRAINT fk_regie_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_regie_kunde        FOREIGN KEY (kunde_id)        REFERENCES kunden (id),
  CONSTRAINT fk_regie_objekt       FOREIGN KEY (objekt_id)       REFERENCES objekte (id),
  CONSTRAINT fk_regie_einsatz      FOREIGN KEY (einsatz_id)      REFERENCES einsaetze (id) ON DELETE SET NULL,
  CONSTRAINT fk_regie_leistungsart FOREIGN KEY (leistungsart_id) REFERENCES leistungsarten (id),
  CONSTRAINT fk_regie_rechnung     FOREIGN KEY (rechnung_id)     REFERENCES rechnungen (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 6. BESICHTIGUNG UND ANGEBOT
-- =====================================================================

CREATE TABLE besichtigungen (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id               INT UNSIGNED NOT NULL,
  kunde_id                 INT UNSIGNED DEFAULT NULL,             -- kann erst später angelegt werden
  benutzer_id              INT UNSIGNED DEFAULT NULL,             -- wer besichtigt hat
  status                   ENUM('entwurf','abgeschlossen','angebot_erstellt') NOT NULL DEFAULT 'entwurf',
  -- Kontakt vor Ort
  ansprechpartner          VARCHAR(120) DEFAULT NULL,
  ansprechpartner_telefon  VARCHAR(40)  DEFAULT NULL,
  -- Objekt
  objektart                VARCHAR(60)  DEFAULT NULL,
  nutzung                  VARCHAR(60)  DEFAULT NULL,
  gesamtflaeche            DECIMAL(8,2) DEFAULT NULL,
  anzahl_raeume            SMALLINT UNSIGNED DEFAULT NULL,
  stockwerke               TINYINT UNSIGNED DEFAULT NULL,
  aufzug                   TINYINT(1)   DEFAULT NULL,
  wc                       TINYINT UNSIGNED DEFAULT NULL,
  urinale                  TINYINT UNSIGNED DEFAULT NULL,
  duschen                  TINYINT UNSIGNED DEFAULT NULL,
  kueche                   TINYINT UNSIGNED DEFAULT NULL,
  empfang                  TINYINT(1)   DEFAULT NULL,
  besprechungsraeume       TINYINT UNSIGNED DEFAULT NULL,
  serverraum               TINYINT(1)   DEFAULT NULL,
  lager                    TINYINT(1)   DEFAULT NULL,
  fenster_anzahl           SMALLINT UNSIGNED DEFAULT NULL,
  fenster_erreichbar       VARCHAR(60)  DEFAULT NULL,
  bodenbelaege             VARCHAR(255) DEFAULT NULL,
  glas_innen               TINYINT(1)   DEFAULT NULL,
  glas_aussen              TINYINT(1)   DEFAULT NULL,
  -- Zustand
  verschmutzung_boeden     TINYINT UNSIGNED DEFAULT NULL,         -- 1–5
  verschmutzung_sanitaer   TINYINT UNSIGNED DEFAULT NULL,
  verschmutzung_kueche     TINYINT UNSIGNED DEFAULT NULL,
  gesamteindruck           TINYINT UNSIGNED DEFAULT NULL,
  red_flags                TEXT         DEFAULT NULL,
  -- Rahmen
  intervall                VARCHAR(40)  DEFAULT NULL,
  reinigungstage           VARCHAR(60)  DEFAULT NULL,
  uhrzeit_von              TIME         DEFAULT NULL,
  uhrzeit_bis              TIME         DEFAULT NULL,
  zutritt                  VARCHAR(120) DEFAULT NULL,
  schluessel_abholung      VARCHAR(120) DEFAULT NULL,
  erreichbarkeit_oeffi     VARCHAR(120) DEFAULT NULL,
  parkmoeglichkeit         VARCHAR(120) DEFAULT NULL,
  material_lagerraum       TINYINT(1)   DEFAULT NULL,
  material_stellt_kunde    TINYINT(1)   DEFAULT NULL,
  strom_wasser_vorhanden   TINYINT(1)   DEFAULT NULL,
  -- Kalkulation
  kalk_stunden_einsatz     DECIMAL(5,2) DEFAULT NULL,
  stundensatz              DECIMAL(8,2) DEFAULT NULL,
  rabatt_prozent           DECIMAL(5,2) DEFAULT NULL,
  material_pauschale       DECIMAL(8,2) DEFAULT NULL,
  kalk_preis_monat         DECIMAL(10,2) DEFAULT NULL,
  -- Sonstiges
  hygiene_info             TEXT         DEFAULT NULL,
  sicherheit_info          TEXT         DEFAULT NULL,
  erwartung_info           TEXT         DEFAULT NULL,
  naechster_schritt        VARCHAR(255) DEFAULT NULL,
  interne_notizen          TEXT         DEFAULT NULL,
  erstellt_am              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_besichtigungen_betrieb_status (betrieb_id, status),
  KEY ix_besichtigungen_kunde (kunde_id),
  CONSTRAINT fk_besichtigungen_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id),
  CONSTRAINT fk_besichtigungen_kunde    FOREIGN KEY (kunde_id)    REFERENCES kunden (id),
  CONSTRAINT fk_besichtigungen_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE besichtigung_fotos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  besichtigung_id INT UNSIGNED NOT NULL,
  pfad            VARCHAR(255) NOT NULL,
  dateiname       VARCHAR(150) DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_besichtigung_fotos_besichtigung (besichtigung_id),
  CONSTRAINT fk_besichtigung_fotos_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_besichtigung_fotos_besichtigung FOREIGN KEY (besichtigung_id) REFERENCES besichtigungen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE angebote (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  kunde_id        INT UNSIGNED NOT NULL,
  besichtigung_id INT UNSIGNED DEFAULT NULL,
  angebotsnummer  VARCHAR(30)  DEFAULT NULL,
  datum           DATE         NOT NULL,
  gueltig_bis     DATE         DEFAULT NULL,
  netto           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ust_satz        DECIMAL(4,2) NOT NULL DEFAULT 20.00,
  ust             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  brutto          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status          ENUM('entwurf','gesendet','angenommen','abgelehnt','abgelaufen') NOT NULL DEFAULT 'entwurf',
  pdf_pfad        VARCHAR(255) DEFAULT NULL,
  gesendet_am     DATETIME     DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aktualisiert_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_angebote_nummer (betrieb_id, angebotsnummer),
  KEY ix_angebote_kunde (kunde_id),
  KEY ix_angebote_betrieb_status (betrieb_id, status),
  CONSTRAINT fk_angebote_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_angebote_kunde        FOREIGN KEY (kunde_id)        REFERENCES kunden (id),
  CONSTRAINT fk_angebote_besichtigung FOREIGN KEY (besichtigung_id) REFERENCES besichtigungen (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE angebot_positionen (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  angebot_id      INT UNSIGNED NOT NULL,
  leistungsart_id INT UNSIGNED DEFAULT NULL,
  beschreibung    VARCHAR(255) NOT NULL,
  menge           DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  einheit         VARCHAR(20)  NOT NULL DEFAULT 'Pauschale',
  einzelpreis     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gesamtpreis     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  sortierung      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_angebot_positionen_angebot (angebot_id, sortierung),
  CONSTRAINT fk_ap_betrieb      FOREIGN KEY (betrieb_id)      REFERENCES betriebe (id),
  CONSTRAINT fk_ap_angebot      FOREIGN KEY (angebot_id)      REFERENCES angebote (id) ON DELETE CASCADE,
  CONSTRAINT fk_ap_leistungsart FOREIGN KEY (leistungsart_id) REFERENCES leistungsarten (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 8. QUALITÄT UND KOMMUNIKATION
-- =====================================================================

CREATE TABLE beschwerden (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id        INT UNSIGNED NOT NULL,
  kunde_id          INT UNSIGNED DEFAULT NULL,
  objekt_id         INT UNSIGNED NOT NULL,
  einsatz_id        INT UNSIGNED DEFAULT NULL,
  mitarbeiter_id    INT UNSIGNED DEFAULT NULL,
  subunternehmer_id INT UNSIGNED DEFAULT NULL,
  datum             DATE         NOT NULL,
  kategorie         VARCHAR(60)  DEFAULT NULL,
  beschreibung      TEXT         NOT NULL,
  massnahmen        TEXT         DEFAULT NULL,
  status            ENUM('offen','in_bearbeitung','erledigt') NOT NULL DEFAULT 'offen',
  bearbeitet_von    INT UNSIGNED DEFAULT NULL,
  bearbeitet_am     DATETIME     DEFAULT NULL,
  erstellt_am       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_beschwerden_betrieb_status (betrieb_id, status, datum),
  KEY ix_beschwerden_objekt (objekt_id),
  KEY ix_beschwerden_mitarbeiter (mitarbeiter_id),
  CONSTRAINT fk_beschwerden_betrieb        FOREIGN KEY (betrieb_id)        REFERENCES betriebe (id),
  CONSTRAINT fk_beschwerden_kunde          FOREIGN KEY (kunde_id)          REFERENCES kunden (id),
  CONSTRAINT fk_beschwerden_objekt         FOREIGN KEY (objekt_id)         REFERENCES objekte (id),
  CONSTRAINT fk_beschwerden_einsatz        FOREIGN KEY (einsatz_id)        REFERENCES einsaetze (id) ON DELETE SET NULL,
  CONSTRAINT fk_beschwerden_mitarbeiter    FOREIGN KEY (mitarbeiter_id)    REFERENCES mitarbeiter (id) ON DELETE SET NULL,
  CONSTRAINT fk_beschwerden_subunternehmer FOREIGN KEY (subunternehmer_id) REFERENCES subunternehmer (id) ON DELETE SET NULL,
  CONSTRAINT fk_beschwerden_bearbeiter     FOREIGN KEY (bearbeitet_von)    REFERENCES benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE kundenfeedback (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id    INT UNSIGNED NOT NULL,
  kunde_id      INT UNSIGNED NOT NULL,
  objekt_id     INT UNSIGNED DEFAULT NULL,
  einsatz_id    INT UNSIGNED DEFAULT NULL,
  bewertung     TINYINT UNSIGNED NOT NULL,                        -- 1–5
  kommentar     TEXT         DEFAULT NULL,
  kontaktperson VARCHAR(120) DEFAULT NULL,
  datum         DATE         NOT NULL,
  erstellt_am   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_kundenfeedback_betrieb (betrieb_id, datum),
  KEY ix_kundenfeedback_objekt (objekt_id),
  CONSTRAINT fk_kundenfeedback_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_kundenfeedback_kunde   FOREIGN KEY (kunde_id)   REFERENCES kunden (id),
  CONSTRAINT fk_kundenfeedback_objekt  FOREIGN KEY (objekt_id)  REFERENCES objekte (id) ON DELETE SET NULL,
  CONSTRAINT fk_kundenfeedback_einsatz FOREIGN KEY (einsatz_id) REFERENCES einsaetze (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Kontaktformular der White-Label-Website
CREATE TABLE anfragen (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  firma       VARCHAR(150) DEFAULT NULL,
  email       VARCHAR(150) NOT NULL,
  telefon     VARCHAR(40)  DEFAULT NULL,
  leistung    VARCHAR(60)  DEFAULT NULL,
  nachricht   TEXT         DEFAULT NULL,
  status      ENUM('neu','kontaktiert','erledigt','spam') NOT NULL DEFAULT 'neu',
  kunde_id    INT UNSIGNED DEFAULT NULL,                          -- gesetzt, sobald daraus ein Kunde wurde
  ip_adresse  VARCHAR(45)  DEFAULT NULL,
  erstellt_am DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_anfragen_betrieb_status (betrieb_id, status, erstellt_am),
  CONSTRAINT fk_anfragen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_anfragen_kunde   FOREIGN KEY (kunde_id)   REFERENCES kunden (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Ausgangswarteschlange für Mails und App-Nachrichten (Cronjob versendet)
CREATE TABLE benachrichtigungen (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id      INT UNSIGNED NOT NULL,
  kanal           ENUM('mail','app') NOT NULL DEFAULT 'mail',
  empfaenger_typ  ENUM('kunde','mitarbeiter','office') NOT NULL,
  empfaenger_id   INT UNSIGNED DEFAULT NULL,
  empfaenger_email VARCHAR(150) DEFAULT NULL,
  betreff         VARCHAR(200) NOT NULL,
  inhalt          TEXT         NOT NULL,
  referenz_typ    VARCHAR(40)  DEFAULT NULL,                      -- z. B. 'einsatz', 'rechnung'
  referenz_id     INT UNSIGNED DEFAULT NULL,
  status          ENUM('wartend','gesendet','fehler') NOT NULL DEFAULT 'wartend',
  gesendet_am     DATETIME     DEFAULT NULL,
  fehler          VARCHAR(255) DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_benachrichtigungen_status (status, erstellt_am),
  KEY ix_benachrichtigungen_betrieb (betrieb_id, erstellt_am),
  CONSTRAINT fk_benachrichtigungen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Welche Ereignisse den Kunden / das Office informieren, je Betrieb steuerbar
CREATE TABLE benachrichtigungs_regeln (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id        INT UNSIGNED NOT NULL,
  ereignis          ENUM('planaenderung','ausfall','ersatz','erledigt','beschwerde','rechnung') NOT NULL,
  kunde_informieren TINYINT(1)   NOT NULL DEFAULT 1,
  office_informieren TINYINT(1)  NOT NULL DEFAULT 1,
  vorlage_betreff   VARCHAR(200) DEFAULT NULL,
  vorlage_text      TEXT         DEFAULT NULL,                    -- Platzhalter {objekt}, {datum}, {uhrzeit}
  aktiv             TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_benachrichtigungs_regeln (betrieb_id, ereignis),
  CONSTRAINT fk_benachrichtigungs_regeln_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- In-App-Postfach der Mitarbeiter
CREATE TABLE app_nachrichten (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id     INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  typ            VARCHAR(40)  NOT NULL DEFAULT 'info',
  titel          VARCHAR(150) NOT NULL,
  nachricht      TEXT         DEFAULT NULL,
  link           VARCHAR(255) DEFAULT NULL,
  gelesen_am     DATETIME     DEFAULT NULL,
  erstellt_am    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_app_nachrichten_ma (mitarbeiter_id, gelesen_am, erstellt_am),
  CONSTRAINT fk_app_nachrichten_betrieb     FOREIGN KEY (betrieb_id)     REFERENCES betriebe (id),
  CONSTRAINT fk_app_nachrichten_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- 9. SYSTEM
-- =====================================================================

CREATE TABLE einstellungen (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  schluessel VARCHAR(80)  NOT NULL,
  wert       TEXT         DEFAULT NULL,
  typ        ENUM('text','zahl','bool','json') NOT NULL DEFAULT 'text',
  PRIMARY KEY (id),
  UNIQUE KEY uq_einstellungen (betrieb_id, schluessel),
  CONSTRAINT fk_einstellungen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id   INT UNSIGNED DEFAULT NULL,
  benutzer_id  INT UNSIGNED DEFAULT NULL,
  aktion       VARCHAR(40)  NOT NULL,                             -- insert/update/delete/login/export …
  tabelle      VARCHAR(60)  DEFAULT NULL,
  datensatz_id INT UNSIGNED DEFAULT NULL,
  beschreibung VARCHAR(255) DEFAULT NULL,
  alte_werte   TEXT         DEFAULT NULL,                         -- JSON als Text (MySQL 5.7-sicher)
  neue_werte   TEXT         DEFAULT NULL,
  ip_adresse   VARCHAR(45)  DEFAULT NULL,
  erstellt_am  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_log_betrieb_zeit (betrieb_id, erstellt_am),
  KEY ix_audit_log_tabelle (tabelle, datensatz_id),
  CONSTRAINT fk_audit_log_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- White-Label-Website je Betrieb
CREATE TABLE website_config (
  betrieb_id          INT UNSIGNED NOT NULL,
  aktiv               TINYINT(1)   NOT NULL DEFAULT 0,
  domain              VARCHAR(150) DEFAULT NULL,
  layout_konzept      VARCHAR(40)  DEFAULT NULL,
  schrift_display     VARCHAR(60)  DEFAULT NULL,
  schrift_text        VARCHAR(60)  DEFAULT NULL,
  farbe_primaer       CHAR(7)      DEFAULT NULL,
  farbe_sekundaer     CHAR(7)      DEFAULT NULL,
  seo_titel           VARCHAR(70)  DEFAULT NULL,
  seo_beschreibung    VARCHAR(160) DEFAULT NULL,
  texte               TEXT         DEFAULT NULL,                  -- JSON als Text
  leistungen_sichtbar TEXT         DEFAULT NULL,                  -- JSON als Text
  oeffnungszeiten     VARCHAR(255) DEFAULT NULL,
  google_analytics_id VARCHAR(30)  DEFAULT NULL,
  recaptcha_aktiv     TINYINT(1)   NOT NULL DEFAULT 0,
  aktualisiert_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (betrieb_id),
  UNIQUE KEY uq_website_config_domain (domain),
  CONSTRAINT fk_website_config_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE website_fotos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  bereich    VARCHAR(40)  NOT NULL,                               -- hero, team, leistungen, …
  pfad       VARCHAR(255) NOT NULL,
  alt_text   VARCHAR(150) DEFAULT NULL,
  sortierung SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_website_fotos_betrieb (betrieb_id, bereich, sortierung),
  CONSTRAINT fk_website_fotos_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- =====================================================================
-- VIEWS (ohne Window-Functions, MySQL 5.7-kompatibel)
-- =====================================================================

-- Einsatzübersicht mit Objekt, Kunde, Leistung und Anzahl Mitarbeiter
CREATE VIEW v_einsaetze_details AS
SELECT
  e.id, e.betrieb_id, e.datum, e.uhrzeit_von, e.uhrzeit_bis, e.status,
  e.dauer_soll_minuten, e.subunternehmer_id, e.kunde_informiert_am,
  o.id AS objekt_id, o.name AS objekt_name, o.adresse AS objekt_adresse, o.plz AS objekt_plz, o.ort AS objekt_ort,
  k.id AS kunde_id, k.firmenname AS kunde_name,
  l.name AS leistung_name, l.kategorie AS leistung_kategorie,
  s.firmenname AS subunternehmer_name,
  (SELECT COUNT(*) FROM einsatz_mitarbeiter em WHERE em.einsatz_id = e.id) AS anzahl_mitarbeiter
FROM einsaetze e
JOIN objekte o        ON o.id = e.objekt_id
JOIN kunden k         ON k.id = o.kunde_id
JOIN leistungsarten l ON l.id = e.leistungsart_id
LEFT JOIN subunternehmer s ON s.id = e.subunternehmer_id;

-- Geleistete Minuten je Mitarbeiter je Monat (Basis für stundenkonto)
CREATE VIEW v_mitarbeiter_monatsminuten AS
SELECT
  z.betrieb_id, z.mitarbeiter_id,
  YEAR(z.checkin_zeit)  AS jahr,
  MONTH(z.checkin_zeit) AS monat,
  COUNT(*)              AS anzahl_einsaetze,
  SUM(z.minuten_netto)  AS minuten_netto
FROM zeiterfassung z
WHERE z.checkout_zeit IS NOT NULL
GROUP BY z.betrieb_id, z.mitarbeiter_id, YEAR(z.checkin_zeit), MONTH(z.checkin_zeit);

-- Offene Regieleistungen je Kunde (noch nicht abgerechnet)
CREATE VIEW v_regie_offen AS
SELECT
  r.betrieb_id, r.kunde_id, k.firmenname AS kunde_name,
  COUNT(*) AS anzahl,
  SUM(r.menge * r.einzelpreis + r.fahrtkosten) AS summe_netto,
  MIN(r.datum) AS aeltestes_datum
FROM regieleistungen r
JOIN kunden k ON k.id = r.kunde_id
WHERE r.rechnung_id IS NULL
GROUP BY r.betrieb_id, r.kunde_id, k.firmenname;

SET FOREIGN_KEY_CHECKS = 1;

-- ===== schema_anbieter.sql =====
SET NAMES utf8mb4;
-- =====================================================================
-- Ergaenzung: Anbieter-Ebene (der Softwarebetreiber, ueber allen Betrieben)
-- In phpMyAdmin nach schema_v2.sql ausfuehren.
-- =====================================================================

CREATE TABLE anbieter_benutzer (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  benutzername    VARCHAR(80)  NOT NULL,
  email           VARCHAR(150) DEFAULT NULL,
  passwort_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(120) DEFAULT NULL,
  rolle           ENUM('superuser','tester') NOT NULL DEFAULT 'tester',
  aktiv           TINYINT(1)   NOT NULL DEFAULT 1,
  letzter_login   DATETIME     DEFAULT NULL,
  fehlversuche    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  gesperrt_bis    DATETIME     DEFAULT NULL,
  erstellt_am     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anbieter_benutzer_name (benutzername)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Notizen des Anbieters zu einem Betrieb (Vertrieb, Support, Absprachen)
CREATE TABLE anbieter_notizen (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id   INT UNSIGNED NOT NULL,
  anbieter_id  INT UNSIGNED NOT NULL,
  notiz        TEXT         NOT NULL,
  erstellt_am  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_anbieter_notizen_betrieb (betrieb_id, erstellt_am),
  CONSTRAINT fk_anbieter_notizen_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id) ON DELETE CASCADE,
  CONSTRAINT fk_anbieter_notizen_anbieter FOREIGN KEY (anbieter_id) REFERENCES anbieter_benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Uebersicht fuer das Anbieter-Dashboard
CREATE VIEW v_anbieter_betriebe AS
SELECT
  b.id, b.name, b.slug, b.email, b.tarif, b.max_mitarbeiter, b.status, b.testphase_bis,
  b.av_vertrag_akzeptiert_am, b.erstellt_am,
  (SELECT COUNT(*) FROM mitarbeiter m WHERE m.betrieb_id = b.id AND m.aktiv = 1)      AS mitarbeiter_aktiv,
  (SELECT COUNT(*) FROM objekte o     WHERE o.betrieb_id = b.id AND o.aktiv = 1)      AS objekte_aktiv,
  (SELECT COUNT(*) FROM kunden k      WHERE k.betrieb_id = b.id AND k.status = 'aktiv') AS kunden_aktiv,
  (SELECT MAX(u.letzter_login) FROM benutzer u WHERE u.betrieb_id = b.id)              AS letzter_login,
  (SELECT u.benutzername FROM benutzer u WHERE u.betrieb_id = b.id AND u.rolle = 'inhaber' ORDER BY u.id LIMIT 1) AS inhaber_login,
  (SELECT COUNT(*) FROM einsaetze e WHERE e.betrieb_id = b.id AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS einsaetze_30_tage
FROM betriebe b;

-- ===== schema_anbieter_abrechnung.sql =====
SET NAMES utf8mb4;
-- =====================================================================
-- Ergaenzung 2: Abrechnung auf Anbieter-Ebene (Tarife, Vertraege, Rechnungen)
-- In phpMyAdmin nach schema_anbieter.sql ausfuehren. Keine ALTER-Befehle.
-- =====================================================================

-- Tarife mit Preisen; aenderbar ohne Codeaenderung
CREATE TABLE anbieter_tarife (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(20)  NOT NULL,
  name            VARCHAR(80)  NOT NULL,
  preis_monat     DECIMAL(8,2) NOT NULL,
  max_mitarbeiter SMALLINT UNSIGNED NOT NULL,
  aktiv           TINYINT(1)   NOT NULL DEFAULT 1,
  sortierung      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anbieter_tarife_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO anbieter_tarife (code, name, preis_monat, max_mitarbeiter, sortierung) VALUES
  ('basis', 'Basis', 89.00, 10, 1),
  ('plus',  'Plus',  149.00, 25, 2),
  ('pro',   'Pro',   249.00, 50, 3);

-- Vertrag je Betrieb: was er tatsaechlich zahlt (kann vom Tarif abweichen)
CREATE TABLE anbieter_vertraege (
  betrieb_id           INT UNSIGNED NOT NULL,
  preis_monat          DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  website_modul        TINYINT(1)   NOT NULL DEFAULT 0,
  website_preis_monat  DECIMAL(8,2) NOT NULL DEFAULT 49.00,
  rabatt_prozent       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  zahlungsweise        ENUM('monatlich','jaehrlich') NOT NULL DEFAULT 'monatlich',
  zahlungsart          ENUM('ueberweisung','sepa') NOT NULL DEFAULT 'ueberweisung',
  ust_satz             DECIMAL(4,2) NOT NULL DEFAULT 20.00,
  vertragsbeginn       DATE         DEFAULT NULL,
  naechste_abrechnung  DATE         DEFAULT NULL,
  kuendigung_zum       DATE         DEFAULT NULL,
  rechnungs_email      VARCHAR(150) DEFAULT NULL,
  rechnungsadresse     VARCHAR(255) DEFAULT NULL,
  uid_nummer           VARCHAR(20)  DEFAULT NULL,
  notizen              TEXT         DEFAULT NULL,
  aktualisiert_am      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (betrieb_id),
  CONSTRAINT fk_anbieter_vertraege_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Rechnungen des Anbieters an die Betriebe (Abo-Rechnungen)
CREATE TABLE anbieter_rechnungen (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id         INT UNSIGNED NOT NULL,
  rechnungsnummer    VARCHAR(30)  NOT NULL,
  rechnungsdatum     DATE         NOT NULL,
  zeitraum_von       DATE         NOT NULL,
  zeitraum_bis       DATE         NOT NULL,
  faellig_am         DATE         NOT NULL,
  positionen         TEXT         NOT NULL,                 -- JSON als Text: [{text, betrag}]
  netto              DECIMAL(10,2) NOT NULL,
  ust_satz           DECIMAL(4,2) NOT NULL,
  ust                DECIMAL(10,2) NOT NULL,
  brutto             DECIMAL(10,2) NOT NULL,
  status             ENUM('offen','bezahlt','storniert') NOT NULL DEFAULT 'offen',
  bezahlt_am         DATE         DEFAULT NULL,
  storno_von_id      INT UNSIGNED DEFAULT NULL,
  pdf_pfad           VARCHAR(255) DEFAULT NULL,
  erstellt_am        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_anbieter_rechnungen_nummer (rechnungsnummer),
  KEY ix_anbieter_rechnungen_betrieb (betrieb_id, rechnungsdatum),
  KEY ix_anbieter_rechnungen_status (status, faellig_am),
  CONSTRAINT fk_anbieter_rechnungen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id),
  CONSTRAINT fk_anbieter_rechnungen_storno  FOREIGN KEY (storno_von_id) REFERENCES anbieter_rechnungen (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Fortlaufende Nummer je Jahr fuer Anbieter-Rechnungen
CREATE TABLE anbieter_nummernkreis (
  jahr          SMALLINT UNSIGNED NOT NULL,
  letzte_nummer INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (jahr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Uebersicht mit Vertrags- und Zahlungsdaten
CREATE OR REPLACE VIEW v_anbieter_betriebe AS
SELECT
  b.id, b.name, b.slug, b.email, b.tarif, b.max_mitarbeiter, b.status, b.testphase_bis,
  b.av_vertrag_akzeptiert_am, b.erstellt_am, b.gekuendigt_am, b.geloescht_am,
  v.preis_monat, v.website_modul, v.website_preis_monat, v.rabatt_prozent, v.zahlungsweise, v.zahlungsart,
  v.ust_satz, v.vertragsbeginn, v.naechste_abrechnung, v.kuendigung_zum,
  -- effektiver Monatsbetrag netto
  ROUND((IFNULL(v.preis_monat,0) + IF(IFNULL(v.website_modul,0)=1, IFNULL(v.website_preis_monat,0), 0)) * (1 - IFNULL(v.rabatt_prozent,0)/100), 2) AS monatsbetrag_netto,
  (SELECT COUNT(*) FROM mitarbeiter m WHERE m.betrieb_id = b.id AND m.aktiv = 1)      AS mitarbeiter_aktiv,
  (SELECT COUNT(*) FROM objekte o     WHERE o.betrieb_id = b.id AND o.aktiv = 1)      AS objekte_aktiv,
  (SELECT COUNT(*) FROM kunden k      WHERE k.betrieb_id = b.id AND k.status = 'aktiv') AS kunden_aktiv,
  (SELECT MAX(u.letzter_login) FROM benutzer u WHERE u.betrieb_id = b.id)              AS letzter_login,
  (SELECT u.benutzername FROM benutzer u WHERE u.betrieb_id = b.id AND u.rolle = 'inhaber' ORDER BY u.id LIMIT 1) AS inhaber_login,
  (SELECT COUNT(*) FROM einsaetze e WHERE e.betrieb_id = b.id AND e.datum >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS einsaetze_30_tage,
  (SELECT IFNULL(SUM(r.brutto),0) FROM anbieter_rechnungen r WHERE r.betrieb_id = b.id AND r.status = 'offen') AS offen_brutto,
  (SELECT COUNT(*) FROM anbieter_rechnungen r WHERE r.betrieb_id = b.id AND r.status = 'offen' AND r.faellig_am < CURDATE()) AS ueberfaellig_anzahl
FROM betriebe b
LEFT JOIN anbieter_vertraege v ON v.betrieb_id = b.id;

-- ===== schema_anbieter_einstellungen.sql =====
SET NAMES utf8mb4;
-- =====================================================================
-- Ergaenzung 3: Anbieter-Stammdaten (Rechnungsaussteller), Mailprotokoll
-- =====================================================================

CREATE TABLE anbieter_einstellungen (
  schluessel VARCHAR(60)  NOT NULL,
  wert       TEXT         DEFAULT NULL,
  PRIMARY KEY (schluessel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO anbieter_einstellungen (schluessel, wert) VALUES
  ('firma', ''), ('inhaber', ''), ('adresse', ''), ('plz', ''), ('ort', ''),
  ('telefon', ''), ('email', ''), ('web', ''),
  ('uid', ''), ('kleinunternehmer', '0'),
  ('bank', ''), ('iban', ''), ('bic', ''),
  ('rechnung_einleitung', 'Wir erlauben uns, folgende Leistungen in Rechnung zu stellen:'),
  ('rechnung_schluss', 'Vielen Dank für Ihr Vertrauen.'),
  ('rechnung_zahlungsziel_tage', '14');

-- Protokoll versendeter Mails (Nachweis, Fehlersuche)
CREATE TABLE anbieter_mails (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id    INT UNSIGNED DEFAULT NULL,
  rechnung_id   INT UNSIGNED DEFAULT NULL,
  empfaenger    VARCHAR(150) NOT NULL,
  betreff       VARCHAR(200) NOT NULL,
  status        ENUM('gesendet','fehler') NOT NULL,
  fehler        VARCHAR(255) DEFAULT NULL,
  gesendet_am   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_anbieter_mails_rechnung (rechnung_id),
  KEY ix_anbieter_mails_betrieb (betrieb_id, gesendet_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ===== schema_zustimmungen.sql =====
SET NAMES utf8mb4;
-- =====================================================================
-- Ergaenzung 4: Zustimmungen der Betriebe zu AV-Vertrag und AGB
-- Texte liegen versioniert in system/texte/; gespeichert werden Version,
-- Hash des Textes, Zeitpunkt, Benutzer und IP — als Nachweis.
-- =====================================================================

CREATE TABLE betrieb_zustimmungen (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id   INT UNSIGNED NOT NULL,
  benutzer_id  INT UNSIGNED NOT NULL,
  typ          ENUM('av_vertrag','agb') NOT NULL,
  version      VARCHAR(10)  NOT NULL,
  text_hash    CHAR(64)     NOT NULL,
  ip_adresse   VARCHAR(45)  DEFAULT NULL,
  zeitpunkt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_zustimmungen_betrieb (betrieb_id, typ, zeitpunkt),
  CONSTRAINT fk_zustimmungen_betrieb  FOREIGN KEY (betrieb_id)  REFERENCES betriebe (id),
  CONSTRAINT fk_zustimmungen_benutzer FOREIGN KEY (benutzer_id) REFERENCES benutzer (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ===== schema_vertrieb.sql =====
-- Anfragen ueber die Vertriebsseite des Anbieters
CREATE TABLE IF NOT EXISTS anbieter_anfragen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  firma VARCHAR(150) DEFAULT NULL,
  email VARCHAR(150) NOT NULL,
  telefon VARCHAR(40) DEFAULT NULL,
  mitarbeiter VARCHAR(20) DEFAULT NULL,
  nachricht TEXT,
  status ENUM('neu','kontaktiert','testphase','kunde','abgelehnt') NOT NULL DEFAULT 'neu',
  ip_adresse VARCHAR(45) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== schema_2fa.sql =====
-- Zwei-Faktor-Anmeldung (TOTP) fuer Buero-Benutzer und Anbieter
ALTER TABLE benutzer ADD COLUMN totp_secret_enc VARBINARY(255) DEFAULT NULL;
ALTER TABLE benutzer ADD COLUMN totp_aktiv TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE benutzer ADD COLUMN totp_letzter_schritt INT UNSIGNED DEFAULT NULL;
ALTER TABLE benutzer ADD COLUMN totp_backup TEXT DEFAULT NULL;
ALTER TABLE anbieter_benutzer ADD COLUMN totp_secret_enc VARBINARY(255) DEFAULT NULL;
ALTER TABLE anbieter_benutzer ADD COLUMN totp_aktiv TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE anbieter_benutzer ADD COLUMN totp_letzter_schritt INT UNSIGNED DEFAULT NULL;
ALTER TABLE anbieter_benutzer ADD COLUMN totp_backup TEXT DEFAULT NULL;

-- ===== schema_reset.sql =====
-- Passwort vergessen (Buero und Kundenportal), Status "gesperrt" fuer abgelaufene Testphasen
CREATE TABLE IF NOT EXISTS passwort_reset (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  benutzer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  laeuft_ab DATETIME NOT NULL,
  verwendet_am DATETIME DEFAULT NULL,
  ip_adresse VARCHAR(45) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_token (token_hash),
  KEY idx_benutzer (benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE betriebe MODIFY status ENUM('test','aktiv','gesperrt','gekuendigt') NOT NULL DEFAULT 'test';

-- ===== schema_qualifikationen.sql =====
-- Qualifikationen je Mitarbeiter, benoetigte Qualifikation je Leistungsart,
-- Einsatzanfragen an Mitarbeiter, Kategorie Haushaltsreinigung
CREATE TABLE IF NOT EXISTS qualifikationen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  beschreibung VARCHAR(255) DEFAULT NULL,
  sortierung SMALLINT NOT NULL DEFAULT 0,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_betrieb (betrieb_id),
  CONSTRAINT fk_qualifikationen_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mitarbeiter_qualifikationen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  qualifikation_id INT UNSIGNED NOT NULL,
  seit DATE DEFAULT NULL,
  nachweis VARCHAR(150) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ma_qual (mitarbeiter_id, qualifikation_id),
  KEY idx_betrieb (betrieb_id),
  CONSTRAINT fk_maq_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE,
  CONSTRAINT fk_maq_qualifikation FOREIGN KEY (qualifikation_id) REFERENCES qualifikationen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS einsatz_anfragen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  einsatz_id INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  status ENUM('offen','zugesagt','abgesagt','abgelaufen','zurueckgezogen') NOT NULL DEFAULT 'offen',
  nachricht VARCHAR(500) DEFAULT NULL,
  antwort VARCHAR(500) DEFAULT NULL,
  gesendet_von INT UNSIGNED DEFAULT NULL,
  gesendet_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  beantwortet_am DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_betrieb_status (betrieb_id, status),
  KEY idx_mitarbeiter (mitarbeiter_id, status),
  CONSTRAINT fk_ea_einsatz FOREIGN KEY (einsatz_id) REFERENCES einsaetze (id) ON DELETE CASCADE,
  CONSTRAINT fk_ea_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE leistungsarten ADD COLUMN qualifikation_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE leistungsarten MODIFY kategorie ENUM('unterhalt','grund','fenster','sonder','haushalt','regie') NOT NULL DEFAULT 'unterhalt';

-- ===== schema_preise.sql (Spalten) =====
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_einmalig DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_verrechnet TINYINT(1) NOT NULL DEFAULT 0;
-- ===== schema_mahnungen.sql =====
-- Mahnwesen (Zahlungserinnerung, Mahnung) mit Verzugszinsen § 456 UGB und Betreibungskosten § 458 UGB
CREATE TABLE IF NOT EXISTS mahnungen (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  rechnung_id INT UNSIGNED NOT NULL,
  stufe TINYINT NOT NULL,
  datum DATE NOT NULL,
  frist_bis DATE NOT NULL,
  offen_betrag DECIMAL(10,2) NOT NULL,
  verzugstage INT NOT NULL DEFAULT 0,
  zinssatz DECIMAL(5,2) NOT NULL DEFAULT 0,
  verzugszinsen DECIMAL(10,2) NOT NULL DEFAULT 0,
  mahnspesen DECIMAL(8,2) NOT NULL DEFAULT 0,
  gesamt DECIMAL(10,2) NOT NULL,
  pdf_pfad VARCHAR(255) DEFAULT NULL,
  gesendet_am DATETIME DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rechnung (rechnung_id),
  KEY idx_betrieb (betrieb_id),
  CONSTRAINT fk_mahnungen_rechnung FOREIGN KEY (rechnung_id) REFERENCES rechnungen (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE rechnungs_layout ADD COLUMN mahnspesen_1 DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE rechnungs_layout ADD COLUMN mahnspesen_2 DECIMAL(8,2) NOT NULL DEFAULT 40.00;
ALTER TABLE rechnungs_layout ADD COLUMN verzugszins_prozent DECIMAL(5,2) NOT NULL DEFAULT 9.20;
ALTER TABLE rechnungs_layout ADD COLUMN mahnfrist_tage TINYINT NOT NULL DEFAULT 10;
-- Anbieter: automatische Abrechnung
INSERT IGNORE INTO anbieter_einstellungen (schluessel, wert) VALUES ('abrechnung_automatisch', '0'), ('abrechnung_senden', '0');
-- ===== schema_push.sql =====
-- Web-Push fuer die Mitarbeiter-App und Kalender-Abo
CREATE TABLE IF NOT EXISTS push_abos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  mitarbeiter_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(500) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) DEFAULT NULL,
  auth VARCHAR(100) DEFAULT NULL,
  geraet VARCHAR(120) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  zuletzt_ok DATETIME DEFAULT NULL,
  fehler INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_endpoint (endpoint_hash),
  KEY idx_mitarbeiter (mitarbeiter_id),
  CONSTRAINT fk_push_mitarbeiter FOREIGN KEY (mitarbeiter_id) REFERENCES mitarbeiter (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE app_nachrichten ADD COLUMN push_am DATETIME DEFAULT NULL;
ALTER TABLE einsatz_anfragen ADD COLUMN push_am DATETIME DEFAULT NULL;
ALTER TABLE benutzer ADD COLUMN kalender_token CHAR(32) DEFAULT NULL;
-- ===== schema_schnellzugang.sql =====
-- Schnellzugang ohne Passwort: nur fuer Testbetriebe, zum eigenen Testen
-- und zum Einladen von Kolleginnen/Kollegen. Wird vom Anbieter erzeugt,
-- oeffnet das Buero direkt (als Inhaber), ohne Benutzername/Passwort.
CREATE TABLE IF NOT EXISTS anbieter_schnellzugang (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  betrieb_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  erstellt_von VARCHAR(80) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab DATETIME NOT NULL,
  nutzungen INT UNSIGNED NOT NULL DEFAULT 0,
  letzte_nutzung DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token_hash),
  UNIQUE KEY uq_betrieb (betrieb_id),
  CONSTRAINT fk_schnellzugang_betrieb FOREIGN KEY (betrieb_id) REFERENCES betriebe (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== schema_direktzugang.sql =====
-- Direktzugang des Superusers in Buero, App und Portal eines Betriebs (Testen ohne Login)
CREATE TABLE IF NOT EXISTS anbieter_direktzugang (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  betrieb_id INT UNSIGNED NOT NULL,
  benutzer_id INT UNSIGNED NOT NULL,
  ziel ENUM('buero','app','portal') NOT NULL,
  erstellt_von VARCHAR(80) DEFAULT NULL,
  laeuft_ab DATETIME NOT NULL,
  verwendet_am DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== schema_anbieter_benutzer.sql (Tabellen) =====
-- Mehrere Anbieter-Benutzer mit Rollen, Passwort-vergessen fuer Anbieter, persoenlicher QR-Login
CREATE TABLE IF NOT EXISTS passwort_reset_anbieter (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anbieter_benutzer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  laeuft_ab DATETIME NOT NULL,
  verwendet_am DATETIME DEFAULT NULL,
  ip_adresse VARCHAR(45) DEFAULT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY idx_token (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS anbieter_login_qr (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  anbieter_benutzer_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  laeuft_ab DATETIME NOT NULL,
  nutzungen INT UNSIGNED NOT NULL DEFAULT 0,
  letzte_nutzung DATETIME DEFAULT NULL,
  PRIMARY KEY (id), UNIQUE KEY uq_token (token_hash), UNIQUE KEY uq_benutzer (anbieter_benutzer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
