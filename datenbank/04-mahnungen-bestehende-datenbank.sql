-- NUR für eine bestehende Datenbank. Bei Neuinstallation mit 01-datenbank-komplett.sql NICHT nötig.
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
