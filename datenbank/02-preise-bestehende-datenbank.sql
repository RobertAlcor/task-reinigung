-- NUR für eine bestehende Datenbank, die vor dem 12.09.2026 angelegt wurde. Bei Neuinstallation mit 01-datenbank-komplett.sql NICHT nötig.
-- Preise 2026: Basis 89 / Plus 149 / Pro 249 (bis 50 Mitarbeiter), Website-Modul 49, Einrichtung einmalig
UPDATE anbieter_tarife SET preis_monat = 89.00,  max_mitarbeiter = 10 WHERE code = 'basis';
UPDATE anbieter_tarife SET preis_monat = 149.00, max_mitarbeiter = 25 WHERE code = 'plus';
UPDATE anbieter_tarife SET preis_monat = 249.00, max_mitarbeiter = 50 WHERE code = 'pro';
ALTER TABLE anbieter_vertraege MODIFY website_preis_monat DECIMAL(8,2) NOT NULL DEFAULT 49.00;
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_einmalig DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE anbieter_vertraege ADD COLUMN einrichtung_verrechnet TINYINT(1) NOT NULL DEFAULT 0;
