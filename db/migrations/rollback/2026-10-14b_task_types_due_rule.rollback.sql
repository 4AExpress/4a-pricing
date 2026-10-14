-- =====================================================================
--  rollback/2026-10-14b_task_types_due_rule.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-14b. Τρέχει ΜΕΤΑ την επαναφορά των
--  api/fuel_lib.php και api/fuel_weekly.php από ~/backups
--  (docs/DEPLOY_PROCEDURE.md, «Επαναφορά»), ποτέ πριν: ο νέος κώδικας
--  χωρίς τις στήλες δεν δημιουργεί εργασίες (σφάλμα στο log), ο παλιός
--  δεν τις διαβάζει.
--
--  Οι εργασίες που δημιουργήθηκαν στο μεταξύ κρατούν το due_at τους.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Όρια χρώματος του verify: πίσω στο NULL του 08c (οι στήλες ΜΕΝΟΥΝ).
UPDATE `4a_task_types` SET `due_warn_hours` = NULL, `due_urgent_hours` = NULL WHERE `code` = 'fuel_weekly_verify';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_time') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `due_time`',
  'SELECT ''η στηλη 4a_task_types.due_time δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_offset_days') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `due_offset_days`',
  'SELECT ''η στηλη 4a_task_types.due_offset_days δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Αναμενόμενο: 0, και fuel_weekly_verify με due_warn_hours / due_urgent_hours NULL.
SELECT COUNT(*) AS `στήλες_που_μένουν`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME IN ('due_offset_days', 'due_time');

SELECT `code`, `due_warn_hours`, `due_urgent_hours`
  FROM `4a_task_types` WHERE `code` = 'fuel_weekly_verify';
