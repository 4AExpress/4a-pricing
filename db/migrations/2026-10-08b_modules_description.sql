-- =====================================================================
--  2026-10-08b_modules_description.sql
--
--  ΠΕΡΙΓΡΑΦΗ ΣΕΛΙΔΑΣ ΑΝΑ MODULE, ΣΤΗ ΒΑΣΗ.
--
--    description  ο υποτιτλος της σελιδας. NULL = καθολου υποτιτλος:
--                 η σελιδα ΔΕΝ εχει εφεδρικο κειμενο στον κωδικα.
--
--  Πρωτη χρηση: η σελιδα Εργασιες (module tasks), μεσω του api/tasks.php.
--  Οι αλλοι modules μενουν NULL και καμια αλλη σελιδα δεν το διαβαζει
--  ακομα.
--
--  ΣΕΙΡΑ: αυτο το migration ΠΡΙΝ ανεβει το tasks.php που διαβαζει τη
--  στηλη. Το tasks.php αντεχει και τη στηλη που λειπει (υποτιτλος NULL).
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'modules'
                   AND COLUMN_NAME  = 'description') = 0,
  'ALTER TABLE `modules`
     ADD COLUMN `description` VARCHAR(255) NULL
       COMMENT ''υποτιτλος σελιδας, NULL = κανενας''
       AFTER `label`',
  'SELECT ''η στηλη description υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `modules`
   SET `description` = 'Εργασίες πελατών μετά την αποδοχή προσφοράς, και εβδομαδιαίες εργασίες συστήματος'
 WHERE `id` = 'tasks';

SELECT `id`, `label`, `description` FROM `modules` ORDER BY `sort_order`, `id`;
