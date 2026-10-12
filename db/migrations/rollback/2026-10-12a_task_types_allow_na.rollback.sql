-- =====================================================================
--  rollback/2026-10-12a_task_types_allow_na.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-12a. Τρέχει ΜΕΤΑ την επαναφορά των αρχείων
--  από ~/backups (docs/DEPLOY_PROCEDURE.md, «Επαναφορά»), ποτέ πριν: ο
--  νέος κώδικας αντέχει την απουσία της στήλης, ο παλιός δεν τη διαβάζει.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_na') = 1,
  'ALTER TABLE `4a_task_types` DROP COLUMN `allow_na`',
  'SELECT ''η στηλη 4a_task_types.allow_na δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Αναμενόμενο: 0.
SELECT COUNT(*) AS `allow_na_υπάρχει`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME  = 'allow_na';
