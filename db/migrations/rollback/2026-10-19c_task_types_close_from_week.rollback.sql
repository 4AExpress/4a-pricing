-- =====================================================================
--  rollback/2026-10-19c_task_types_close_from_week.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-19c. Η στήλη φεύγει μαζί με τη σημαία του
--  fuel_weekly_verify. Ο νέος κώδικας αντέχει την απουσία της (θεωρεί 0),
--  άρα μπορεί να τρέξει και χωρίς επαναφορά των αρχείων.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'close_from_week_start') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `close_from_week_start`',
  'SELECT ''η στηλη 4a_task_types.close_from_week_start δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Αναμενόμενο: 0.
SELECT COUNT(*) AS `close_from_week_start_υπάρχει`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME  = 'close_from_week_start';
