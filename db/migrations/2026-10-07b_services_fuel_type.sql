-- 2026-10-07b_services_fuel_type.sql
--
-- ΤΥΠΟΣ ΜΕΤΑΦΟΡΑΣ ΚΑΙ ΤΥΠΟΣ ΕΠΙΝΑΥΛΟΥ ΕΙΝΑΙ ΔΥΟ ΔΙΑΦΟΡΕΤΙΚΑ ΠΡΑΓΜΑΤΑ
--
--   4a_services.type       ο τροπος μεταφορας της υπηρεσιας
--                          AIR, ROAD, SEA, COMBI
--   4a_services.fuel_type  ποιος επιναυλος εφαρμοζεται
--                          AIR, ROAD, AIR_CY, NONE, η NULL για τις COMBI
--
-- Για τις COMBI τα δυο ΔΙΑΦΕΡΟΥΝ ΣΚΟΠΙΜΑ. Η S1050 μεταφερει συνδυασμενα,
-- αεροπορικα και οδικα, αρα type COMBI. Ο επιναυλος της ομως ειναι ROAD,
-- γιατι χρεωνεται με βαση το σκελος Ελλαδα προς κοσμο. Επιβεβαιωθηκε στο
-- CMS στις 07-10-2026, S1050 και S1051 με 40 τοις εκατο.
--
-- Η ΕΠΙΛΥΣΗ ΓΙΝΕΤΑΙ ΜΕ ΚΑΝΟΝΑ, ΟΧΙ ΜΕ ΛΙΣΤΑ ΚΩΔΙΚΩΝ
--
-- Οι COMBI κρατουν fuel_type NULL. Ο τυπος τους προκυπτει απο την υπηρεσια
-- που δειχνει το tariff_source, δηλαδη το σκελος Ελλαδα προς κοσμο. Η
-- επιλυση ζει στο view v_services_fuel, ωστε αν αλλαξει ο τυπος της βασης
-- υπηρεσιας να ακολουθησει αυτοματα και η COMBI. Καμια αποθηκευμενη
-- αντιγραφη, καμια πιθανοτητα να παλιωσει.
--
-- ΓΙΑΤΙ ΔΕΝ ΕΙΝΑΙ GENERATED COLUMN: η MySQL δεν επιτρεπει υποερωτημα η
-- self join σε generated expression. Trigger θα εκρυβε τον κανονα.
--
-- ΓΙΑΤΙ ΠΡΩΤΟ ΤΟ ΡΗΤΟ: οι παραλλαγες _GR και _CY εχουν tariff_source S1003
-- η S1012, που ειναι AIR. Αν η επιλυση κληρονομουσε παντα απο το
-- tariff_source, θα επαιρναν λαθος AIR αντι NONE. Το COALESCE βαζει πρωτη
-- τη ρητη τιμη και το NULL μενει μονο για τις COMBI.
--
-- ΦΥΛΑΚΕΣ: το join απορριπτει πηγη που ειναι η ιδια η υπηρεσια και πηγη που
-- ειναι κι αυτη COMBI. Σε αυτες τις περιπτωσεις το επιλυμενο fuel_type
-- μενει NULL, και το τεστ συνεπειας βγαζει σφαλμα. Δεν σιωπα.
--
-- MySQL 8.4.6. Το ADD COLUMN γινεται με information_schema και PREPARE,
-- γιατι το ADD COLUMN IF NOT EXISTS ειναι συνταξη MariaDB.

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = '4a_services'
     AND COLUMN_NAME  = 'fuel_type'
);

SET @ddl := IF(@has_col = 0,
  'ALTER TABLE `4a_services`
     ADD COLUMN `fuel_type` varchar(16)
       COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `has_cod`',
  'DO 0'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Αρχικες τιμες. Ρητες λιστες κωδικων, ωστε το migration να ειναι
-- ελεγξιμο γραμμη προς γραμμη. Το μοτιβο που ακολουθουν, για αναφορα:
-- AIR οσες ειναι type AIR και δεν ειναι ζωνης z9, ROAD οσες ειναι type
-- ROAD, NONE οσες ειναι SEA η ζωνης z9.

UPDATE `4a_services` SET `fuel_type` = 'AIR'
 WHERE `code` IN ('S1003', 'S1012');

UPDATE `4a_services` SET `fuel_type` = 'ROAD'
 WHERE `code` IN ('S1010', 'S1041');

UPDATE `4a_services` SET `fuel_type` = 'NONE'
 WHERE `code` IN ('S1039', 'S1059', 'S1003_GR', 'S1003_CY', 'S1012_GR', 'S1012_CY');

-- Οι COMBI με κανονα, οχι με λιστα κωδικων.
UPDATE `4a_services` SET `fuel_type` = NULL
 WHERE `type` = 'COMBI';

-- Το view ειναι η ΜΟΝΗ πηγη επιλυμενου fuel_type. Καθε αναγνωση περνα απο
-- εδω, ποτε απευθειας απο τη στηλη.
CREATE OR REPLACE VIEW `v_services_fuel` AS
SELECT
  s.`code`,
  s.`name`,
  s.`type`,
  s.`tariff_source`,
  s.`zone_list`,
  s.`country`,
  s.`direction`,
  s.`has_fuel`,
  s.`has_cod`,
  s.`sort_order`,
  s.`active`,
  s.`fuel_type`                                AS `fuel_type_stored`,
  COALESCE(s.`fuel_type`, src.`fuel_type`)     AS `fuel_type`,
  src.`code`                                   AS `fuel_type_from`,
  CASE WHEN s.`fuel_type` IS NULL AND src.`fuel_type` IS NOT NULL
       THEN 1 ELSE 0 END                       AS `fuel_type_inherited`
FROM `4a_services` s
LEFT JOIN `4a_services` src
       ON src.`code` = s.`tariff_source`
      AND src.`code` <> s.`code`
      AND src.`type` <> 'COMBI';
