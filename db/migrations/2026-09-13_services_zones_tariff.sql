-- =====================================================================
--  4a-pricing · Ζώνες και πίνακας κόστους ανά υπηρεσία
--  Αρχείο: 2026-09-13_services_zones_tariff.sql
--
--  ΓΙΑΤΙ:
--  Ο αριθμός ζωνών και ο πίνακας DHL ζουν σήμερα σε ΕΞΙ λίστες μέσα
--  στον κώδικα (_ZN ×4, _MODAL_ZN, _DHL_ALIAS) συν το SVC_INFO σε
--  άλλο αρχείο. Διαφωνούν μεταξύ τους σε έξι από τις δώδεκα
--  υπηρεσίες. Αποτέλεσμα στο CMS export:
--    · S1050/S1051 βγάζουν 7 ζώνες αντί για 3, με κόστη AIR ενώ ο
--      τιμοκατάλογος χτίστηκε σε ROAD -> αρνητικά AddRate
--    · S1003_CY/S1012_CY βγάζουν z1…z7 αντί για τη μία γραμμή z9
--
--  ΓΙΑΤΙ ΛΙΣΤΑ ΚΑΙ ΟΧΙ ΑΡΙΘΜΟΣ:
--  Το «μία ζώνη» δεν λέει ΠΟΙΑ. Η Z9 είναι η ειδική ζώνη
--  Ελλάδα<->Κύπρος του CMS, όχι η z1. Από τη λίστα βγαίνει και το
--  πλήθος· από το πλήθος δεν βγαίνει η λίστα.
--
--  ΑΣΦΑΛΕΣ: δύο νέες στήλες με default, μετά UPDATE ανά κωδικό.
--  Καμία διαγραφή. Idempotent — ξανατρέχει χωρίς ζημιά.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. ΠΡΙΝ ΓΡΑΨΟΥΜΕ: sql_mode και ΟΛΟΙ οι κωδικοί της βάσης
-- ---------------------------------------------------------------------
SELECT @@sql_mode;
SELECT COUNT(*) AS services_total FROM `4a_services`;
SELECT `code` FROM `4a_services` ORDER BY `code`;


-- ---------------------------------------------------------------------
-- 1. Οι στήλες (έλεγχος μέσω information_schema — η MySQL εδώ δεν
--    δέχεται ADD COLUMN IF NOT EXISTS). Χωρίς AFTER: η σειρά στηλών
--    δεν μας ενδιαφέρει και έτσι δεν εξαρτάται από άλλο migration.
-- ---------------------------------------------------------------------
SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_services'
      AND COLUMN_NAME='zone_list') = 0,
  'ALTER TABLE `4a_services`
     ADD COLUMN `zone_list` VARCHAR(64) NOT NULL DEFAULT ''''
     COMMENT ''Ζώνες που εξάγει, csv: z1,z2,z3 ή z9''',
  'SELECT ''zone_list: υπάρχει ήδη'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_services'
      AND COLUMN_NAME='tariff_source') = 0,
  'ALTER TABLE `4a_services`
     ADD COLUMN `tariff_source` VARCHAR(20) NOT NULL DEFAULT ''''
     COMMENT ''Ποιον πίνακα DHL διαβάζει για κόστος''',
  'SELECT ''tariff_source: υπάρχει ήδη'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 2. Τιμές — επιβεβαιωμένες από τον ιδιοκτήτη, 13/09/2026
--
--    Z9 = η ειδική ζώνη Ελλάδα<->Κύπρος.
--    Τα _GR/_CY variants είναι εσκεμμένα μονοζωνικοί τιμοκατάλογοι:
--    η δουλειά είναι Ελλάδα-Κύπρος και ο πελάτης διαβάζει καλύτερα
--    έναν τιμοκατάλογο για τη διαδρομή που τον αφορά.
--
--    Τα COMBI έχουν πάντα οδικό σκέλος -> Ευρώπη μόνο -> ROAD, 3 ζώνες.
-- ---------------------------------------------------------------------

-- Διεθνείς, αεροπορικοί (7 ζώνες, χωρίς z9)
UPDATE `4a_services` SET `zone_list`='z1,z2,z3,z4,z5,z6,z7', `tariff_source`='S1003' WHERE `code`='S1003';
UPDATE `4a_services` SET `zone_list`='z1,z2,z3,z4,z5,z6,z7', `tariff_source`='S1012' WHERE `code`='S1012';

-- Μονοζωνικοί Ελλάδα<->Κύπρος. Κόστος από τον αντίστοιχο AIR πίνακα.
UPDATE `4a_services` SET `zone_list`='z9', `tariff_source`='S1003' WHERE `code` IN ('S1003_GR','S1003_CY');
UPDATE `4a_services` SET `zone_list`='z9', `tariff_source`='S1012' WHERE `code` IN ('S1012_GR','S1012_CY');

-- Οδικοί, Ευρώπη (3 ζώνες)
UPDATE `4a_services` SET `zone_list`='z1,z2,z3', `tariff_source`='S1010' WHERE `code`='S1010';
UPDATE `4a_services` SET `zone_list`='z1,z2,z3', `tariff_source`='S1041' WHERE `code`='S1041';

-- COMBI. ΠΡΟΣΟΧΗ: πίνακας ROAD, όχι AIR — εδώ ήταν το σφάλμα.
UPDATE `4a_services` SET `zone_list`='z1,z2,z3', `tariff_source`='S1010' WHERE `code`='S1050';
UPDATE `4a_services` SET `zone_list`='z1,z2,z3', `tariff_source`='S1041' WHERE `code`='S1051';

-- Θαλάσσιοι, Ελλάδα<->Κύπρος
UPDATE `4a_services` SET `zone_list`='z9', `tariff_source`='S1039' WHERE `code`='S1039';
UPDATE `4a_services` SET `zone_list`='z9', `tariff_source`='S1059' WHERE `code`='S1059';


-- ---------------------------------------------------------------------
-- 3. ΕΛΕΓΧΟΣ
--    Κάθε γραμμή με κενό tariff_source είναι υπηρεσία που προστέθηκε
--    χωρίς να οριστεί πίνακας κόστους — θα σκάσει στην εξαγωγή, και
--    αυτό είναι το σωστό. Καμία σιωπηλή προεπιλογή.
-- ---------------------------------------------------------------------
SELECT `code`, `name`, `type`, `country`, `direction`,
       `zone_list`, `tariff_source`, `active`
  FROM `4a_services`
 ORDER BY `tariff_source`, `code`;

SELECT `code` AS `ΧΩΡΙΣ ΠΙΝΑΚΑ ΚΟΣΤΟΥΣ`
  FROM `4a_services`
 WHERE `tariff_source` = '' AND `active` = 1;
