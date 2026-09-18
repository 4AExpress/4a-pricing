-- =====================================================================
--  4a-pricing · Ράφι: σήμανση «συχνά χρησιμοποιούμενος» (⭐)
--  Αρχείο: 2026-09-18_shelf_is_hot.sql
--
--  ΓΙΑΤΙ:
--  Η λίστα ραφιού θα αποκτήσει ✏️ επεξεργασία (label, category, is_hot).
--  Το label και το category τα πρόσθεσε το 2026-09-15_shelf_labels.sql·
--  το is_hot δεν υπάρχει πουθενά.
--
--  Χειροκίνητο ⭐ — ΟΧΙ υπολογισμένο από χρήση. Κανένας τιμοκατάλογος
--  δεν σημαδεύεται αυτόματα: όλοι ξεκινούν με 0 και τους σημαδεύει ο
--  ιδιοκτήτης.
--
--  ΑΣΦΑΛΕΣ: μία νέα στήλη με default. Κανένα UPDATE, καμία διαγραφή.
--  Idempotent.
-- =====================================================================

SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=@db AND TABLE_NAME='4a_shelf'
                   AND COLUMN_NAME='is_hot')=0,
  "ALTER TABLE `4a_shelf` ADD COLUMN `is_hot` TINYINT(1) NOT NULL DEFAULT 0
     COMMENT 'Συχνά χρησιμοποιούμενος — χειροκίνητο ⭐'",
  "SELECT 'is_hot: υπάρχει ήδη' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- ---------------------------------------------------------------------
-- Έλεγχος
-- ---------------------------------------------------------------------
SHOW COLUMNS FROM `4a_shelf` LIKE 'is_hot';
SELECT COUNT(*) FROM `4a_shelf_categories`;
SELECT COUNT(*) AS ΧΩΡΙΣ_LABEL FROM `4a_shelf` WHERE label='';
