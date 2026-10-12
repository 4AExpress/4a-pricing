-- =====================================================================
--  rollback/2026-10-12b_task_steps.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-12b. Τρέχει ΜΕΤΑ την επαναφορά των αρχείων
--  από ~/backups. ΣΤΟΠ αν ο πίνακας έχει γραμμές: τότε έχει τρέξει
--  migration ενεργοποίησης, που έχει δικό του αντίστροφο και πάει πρώτο.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Αναμενόμενο: 0 γραμμές ή «δεν υπάρχει». Αλλιώς ΣΤΟΠ, πριν από το DROP.
SELECT `TABLE_NAME`, `TABLE_ROWS`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_steps';

SET @n := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_task_steps');
SET @sql := IF(@n = 1,
  'SELECT COUNT(*) INTO @rows FROM `4a_task_steps`',
  'SELECT 0 INTO @rows');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(@n = 1 AND @rows = 0,
  'DROP TABLE `4a_task_steps`',
  IF(@n = 0, 'SELECT ''ο πινακας 4a_task_steps δεν υπαρχει'' AS `ΠΑΡΑΛΕΙΨΗ`',
             'SELECT ''ΣΤΟΠ: ο 4a_task_steps εχει γραμμες, πρωτα το αντιστροφο της ενεργοποιησης'' AS `ΣΦΑΛΜΑ`'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
