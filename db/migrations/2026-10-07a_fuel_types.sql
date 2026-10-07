-- 2026-10-07a_fuel_types.sql
--
-- ΛΕΞΙΛΟΓΙΟ ΤΥΠΩΝ ΕΠΙΝΑΥΛΟΥ. Ετικετες και κανονες στη βαση, ποτε στον κωδικα.
--
--   source     ποια τιμη του cache διαβαζεται, air η road. NULL σημαινει
--              καμια αναγνωση, δηλαδη καμια χρεωση.
--   multiplier ποσο πολλαπλασιαζεται η τιμη της πηγης.
--
-- Το AIR_CY ειναι ο επιναυλος Air Cyprus και ισουται με AIR επι 1.06, κανονας
-- της εταιρειας. Αντικαθιστα την παλια ετικετα Valuable, που ηταν λαθος, και
-- τη σκληρη σταθερα VALUABLE_MULTIPLIER του dashboard.
--
-- Το NONE δηλωνει ρητα οτι η υπηρεσια δεν παιρνει επιναυλο. Υπαρχει ως τιμη
-- ωστε η απουσια χρεωσης να ειναι δεδομενο και οχι κενο πεδιο.
--
-- ΣΥΜΒΑΤΟΤΗΤΑ: collation utf8mb4_unicode_ci, ιδια με το 4a_services.code,
-- ωστε τα join να μην σκαζουν με σφαλμα 1267.
--
-- MySQL 8.4.6. Το CREATE TABLE IF NOT EXISTS ειναι προτυπη συνταξη και
-- υποστηριζεται. Δεν χρησιμοποιειται πουθενα ALTER TABLE ADD COLUMN IF NOT
-- EXISTS, που ειναι συνταξη MariaDB.

CREATE TABLE IF NOT EXISTS `4a_fuel_types` (
  `code`       varchar(16)        NOT NULL,
  `label_el`   varchar(64)        NOT NULL,
  `label_en`   varchar(64)        NOT NULL,
  `source`     enum('air','road')     NULL DEFAULT NULL,
  `multiplier` decimal(6,4)           NULL DEFAULT NULL,
  `sort_order` int                NOT NULL DEFAULT 0,
  `active`     tinyint(1)         NOT NULL DEFAULT 1,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ιδεμποτεντικο: η επαναληψη του migration ξαναγραφει τις ιδιες τιμες και
-- δεν δημιουργει διπλες γραμμες.
INSERT INTO `4a_fuel_types`
  (`code`, `label_el`, `label_en`, `source`, `multiplier`, `sort_order`, `active`)
VALUES
  ('AIR',    'Αεροπορικός',    'Air',               'air',  1.0000, 10, 1),
  ('ROAD',   'Οδικός',         'Road',              'road', 1.0000, 20, 1),
  ('AIR_CY', 'Air Cyprus',     'Air Cyprus',        'air',  1.0600, 30, 1),
  ('NONE',   'Χωρίς επίναυλο', 'No fuel surcharge',  NULL,    NULL, 40, 1)
ON DUPLICATE KEY UPDATE
  `label_el`   = VALUES(`label_el`),
  `label_en`   = VALUES(`label_en`),
  `source`     = VALUES(`source`),
  `multiplier` = VALUES(`multiplier`),
  `sort_order` = VALUES(`sort_order`),
  `active`     = VALUES(`active`);
