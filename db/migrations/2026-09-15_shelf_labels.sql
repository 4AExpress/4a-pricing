-- =====================================================================
--  4a-pricing · Ράφι: τίτλος, κατηγορία, έτος, soft delete
--  Αρχείο: 2026-09-15_shelf_labels.sql
--
--  ΓΙΑΤΙ:
--  Το 4a_shelf έχει 62 εγγραφές και κανένα δομημένο πεδίο πέρα από το
--  service_id. Ο τίτλος, η κατηγορία και το έτος ζουν σήμερα ανάμεικτα
--  μέσα στο `name`:
--      "S1003_GR · 15↔2 | 4↑10 | 3.8↑20 | 3.5↑ KAR"
--  Οι ετικέτες στο τέλος είναι άλλοτε ονόματα πελατών (KAR, GAL, M&R),
--  άλλοτε ζώνες όγκου (SKG20, 200PLUS), άλλοτε δοκιμές (ΤΕΣΤ, DEMO, 000).
--  Κανένα φίλτρο δεν μπορεί να στηριχθεί σε αυτό.
--
--  ΚΑΙ ΕΝΑ ΠΡΑΓΜΑΤΙΚΟ ΠΡΟΒΛΗΜΑ:
--  Το shelf.php κάνει σκληρό DELETE. Εννιά στοιχεία πελατών δείχνουν σε
--  σβησμένους τιμοκαταλόγους, και πέντε από αυτά δεν έχουν τιμές
--  πουθενά. Το `active` το σταματά να ξανασυμβεί.
--
--  ΑΣΦΑΛΕΣ: νέες στήλες με default, νέος πίνακας, UPDATE σε δικές μας
--  στήλες μόνο. Καμία διαγραφή. Idempotent.
-- =====================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------
-- 1. Κατηγορίες — πίνακας, όχι σταθερή λίστα
--    Ο ιδιοκτήτης θα προσθέτει κατηγορίες από το UI χωρίς αλλαγή κώδικα.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_shelf_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(40)  NOT NULL COMMENT 'ESHOPS, 20-50 — μπαίνει στο label',
  `label`      VARCHAR(80)  NOT NULL COMMENT 'τι βλέπει ο χρήστης',
  `kind`       ENUM('volume','sector') NOT NULL DEFAULT 'sector',
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Το `kind` ξεχωρίζει όγκο από κλάδο. Δεν αλλάζει τη λογική σήμερα —
-- ένα πεδίο, μία επιλογή — αλλά επιτρέπει αργότερα δύο dropdown ή
-- ομαδοποίηση στη λίστα, χωρίς νέο migration.

INSERT INTO `4a_shelf_categories` (`code`,`label`,`kind`,`sort_order`) VALUES
  ('1-5',           '1-5 αποστολές/μήνα',     'volume', 10),
  ('5-10',          '5-10 αποστολές/μήνα',    'volume', 20),
  ('20-50',         '20-50 αποστολές/μήνα',   'volume', 30),
  ('50-100',        '50-100 αποστολές/μήνα',  'volume', 40),
  ('100-200',       '100-200 αποστολές/μήνα', 'volume', 50),
  ('200+',          '200+ αποστολές/μήνα',    'volume', 60),
  ('ESHOPS',        'E-shops',                'sector', 110),
  ('AUTO SUPPLIES', 'Ανταλλακτικά οχημάτων',  'sector', 120),
  ('DENTAL',        'Οδοντιατρικά',           'sector', 130),
  ('MEDICAL',       'Ιατρικά',                'sector', 140),
  ('ΝΑΥΤΙΛΙΑΚΕΣ',   'Ναυτιλιακές',            'sector', 150)
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `sort_order`=VALUES(`sort_order`);


-- ---------------------------------------------------------------------
-- 2. Νέες στήλες στο 4a_shelf
--    ΟΛΕΣ με κενό default, ΠΟΤΕ με τιμή που μοιάζει έγκυρη.
--    Κενό = «δεν ορίστηκε» και φαίνεται· μια πλασματική προεπιλογή
--    κρύβεται και ταξιδεύει σε τιμολόγια.
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='label')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `label` VARCHAR(80) NOT NULL DEFAULT ''
     COMMENT 'Διακριτικός τίτλος, π.χ. 1ESHOP2026'",
  "SELECT 'label: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='category')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `category` VARCHAR(40) NOT NULL DEFAULT ''
     COMMENT 'code από 4a_shelf_categories'",
  "SELECT 'category: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='year')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `year` SMALLINT UNSIGNED NOT NULL DEFAULT 0
     COMMENT 'Έτος τιμοκαταλόγου — ανατίμηση κάθε Ιανουάριο'",
  "SELECT 'year: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='tariff_version')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `tariff_version` VARCHAR(20) NOT NULL DEFAULT ''
     COMMENT 'Ποια γενιά dhl_tariff χρησιμοποιήθηκε'",
  "SELECT 'tariff_version: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='active')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `active` TINYINT(1) NOT NULL DEFAULT 1
     COMMENT 'soft delete — 9 στοιχεία πελατών δείχνουν ήδη σε σβησμένους'",
  "SELECT 'active: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- 3. Συμπλήρωση μόνο όπου υπάρχει ΒΕΒΑΙΟΤΗΤΑ
--
--    year: από το created_at. Όλες οι 62 εγγραφές είναι 2026.
--    tariff_version: '2026'. Υπάρχει μόνο μία γενιά αρχείων
--      (dhl_tariff_2026_*), άρα δεν υπάρχει άλλη δυνατή τιμή.
--
--    label και category ΜΕΝΟΥΝ ΚΕΝΑ. Τα ονόματα είναι ανάμεικτα και
--    καμία αυτόματη εξαγωγή δεν θα ήταν αξιόπιστη: το "KAR" είναι
--    πελάτης, το "SKG20" ζώνη όγκου, το "ΤΕΣΤ" δοκιμή. Θα τα
--    συμπληρώσει ο ιδιοκτήτης.
-- ---------------------------------------------------------------------
UPDATE `4a_shelf` SET `year` = YEAR(`created_at`) WHERE `year` = 0;
UPDATE `4a_shelf` SET `tariff_version` = '2026'   WHERE `tariff_version` = '';


-- ---------------------------------------------------------------------
-- 4. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `code`,`label`,`kind`,`sort_order` FROM `4a_shelf_categories`
 ORDER BY `sort_order`;

SELECT `year`, `tariff_version`, COUNT(*) AS n
  FROM `4a_shelf` GROUP BY `year`, `tariff_version`;

SELECT COUNT(*) AS `ΧΩΡΙΣ ΤΙΤΛΟ`     FROM `4a_shelf` WHERE `label` = '';
SELECT COUNT(*) AS `ΧΩΡΙΣ ΚΑΤΗΓΟΡΙΑ` FROM `4a_shelf` WHERE `category` = '';
SELECT COUNT(*) AS `ΕΝΕΡΓΑ`          FROM `4a_shelf` WHERE `active` = 1;
