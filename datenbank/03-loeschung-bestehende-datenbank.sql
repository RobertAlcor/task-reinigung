-- NUR für eine bestehende Datenbank. Bei Neuinstallation mit 01-datenbank-komplett.sql NICHT nötig.
-- Vertragsende und Loeschfrist (Art. 28 DSGVO, AV-Vertrag: Loeschung 30 Tage nach Vertragsende)
ALTER TABLE betriebe ADD COLUMN gekuendigt_am DATE DEFAULT NULL;
ALTER TABLE betriebe ADD COLUMN geloescht_am DATETIME DEFAULT NULL;
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
