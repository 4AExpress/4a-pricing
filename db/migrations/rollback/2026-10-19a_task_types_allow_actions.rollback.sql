-- =====================================================================
--  rollback/2026-10-19a_task_types_allow_actions.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-19a. Τρέχει ΜΕΤΑ την επαναφορά των αρχείων
--  από ~/backups (docs/DEPLOY_PROCEDURE.md, «Επαναφορά»), ποτέ πριν: ο
--  νέος κώδικας αντέχει την απουσία των στηλών, ο παλιός δεν τις διαβάζει.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_release') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `allow_release`',
  'SELECT ''η στηλη 4a_task_types.allow_release δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_reject') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `allow_reject`',
  'SELECT ''η στηλη 4a_task_types.allow_reject δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_steal') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `allow_steal`',
  'SELECT ''η στηλη 4a_task_types.allow_steal δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Αναμενόμενο: 0.
SELECT COUNT(*) AS `στήλες_που_μένουν`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME IN ('allow_release', 'allow_reject', 'allow_steal');
