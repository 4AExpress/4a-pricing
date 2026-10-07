-- =====================================================================
--  2026-10-07e_tasks_system_subject.sql
--
--  ΕΡΓΑΣΙΕΣ ΧΩΡΙΣ ΠΕΛΑΤΗ (εργασιες συστηματος, π.χ. εβδομαδιαιος
--  γενικος επιναυλος). Φαση 2 της εργασιας επιναυλου.
--
--    client_id    γινεται NULL. Εργασια πελατη: client_id. Εργασια
--                 συστηματος: subject_key.
--    subject_key  ταυτοτητα εργασιας συστηματος, π.χ. fuel:2026-10-12
--    country      χωρα της εργασιας οταν δεν υπαρχει πελατης.
--                 GR, CY ή BOTH. Για εργασιες πελατη μενει NULL και η
--                 χωρα ερχεται απο τον πελατη (COALESCE στο tasks_lib).
--    dedupe_key   STORED. Πελατης: c:<client_id>:<offer_number>.
--                 Συστημα: subject_key.
--    CHECK        καθε εργασια εχει πελατη Η subject_key.
--
--  Το UNIQUE(task_code, dedupe_key) αντικαθιστα το
--  UNIQUE(client_id, task_code, offer_number) και ειναι ισοδυναμο για τις
--  εργασιες πελατη: το client_id ειναι αριθμος χωρις ανω-κατω τελεια,
--  αρα το c:<id>: οριοθετει μοναδικα. Το offer_number ειναι NOT NULL
--  DEFAULT κενο, αρα το COALESCE δεν αλλαζει τιποτα σημερα.
--  Το ADD UNIQUE και το DROP INDEX γινονται στο ΙΔΙΟ ALTER, ωστε να μην
--  υπαρχει στιγμη χωρις μοναδικοτητα.
--
--  ΠΡΙΝ: ο ελεγχος διπλων (βημα 0) πρεπει να δινει 0. Αν δεν δινει 0,
--  το ADD UNIQUE αποτυγχανει και ο πινακας μενει ως ηταν.
--
--  Το ix_client μενει: χρησιμοποιειται στο tasks_create.php.
--
--  MySQL 8.4.6. Idempotent. Καμια συνταξη MariaDB.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 0. Ελεγχος διπλων με το νεο κλειδι, πριν απο οτιδηποτε. Αναμενεται 0.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS `διπλα_πριν`
  FROM (SELECT `task_code`,
               CONCAT('c:', `client_id`, ':', COALESCE(`offer_number`, '')) AS k
          FROM `4a_tasks`
         GROUP BY `task_code`, k
        HAVING COUNT(*) > 1) d;

-- ---------------------------------------------------------------------
-- 1. client_id NULL, subject_key, country, dedupe_key, CHECK
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'client_id') = 'NO',
  'ALTER TABLE `4a_tasks`
     MODIFY COLUMN `client_id` BIGINT NULL
       COMMENT ''-> 4a_clients.id (BIGINT signed). NULL = εργασια συστηματος, βλ. subject_key''',
  'SELECT ''το client_id ειναι ηδη NULL'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'subject_key') = 0,
  'ALTER TABLE `4a_tasks`
     ADD COLUMN `subject_key` VARCHAR(80) NULL
       COMMENT ''ταυτοτητα εργασιας συστηματος, π.χ. fuel:2026-10-12''
       AFTER `client_id`',
  'SELECT ''η στηλη subject_key υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'country') = 0,
  'ALTER TABLE `4a_tasks`
     ADD COLUMN `country` ENUM(''GR'',''CY'',''BOTH'') NULL
       COMMENT ''χωρα εργασιας χωρις πελατη. NULL = απο τον πελατη''
       AFTER `subject_key`',
  'SELECT ''η στηλη country υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'dedupe_key') = 0,
  'ALTER TABLE `4a_tasks`
     ADD COLUMN `dedupe_key` VARCHAR(80)
       GENERATED ALWAYS AS (CASE WHEN `client_id` IS NOT NULL
                                 THEN CONCAT(''c:'', `client_id`, '':'', COALESCE(`offer_number`, ''''))
                                 ELSE `subject_key` END) STORED
       COMMENT ''κλειδι μοναδικοτητας: c:<client>:<offer> ή subject_key''
       AFTER `country`',
  'SELECT ''η στηλη dedupe_key υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA    = DATABASE()
                   AND TABLE_NAME      = '4a_tasks'
                   AND CONSTRAINT_NAME = 'chk_task_subject') = 0,
  'ALTER TABLE `4a_tasks`
     ADD CONSTRAINT `chk_task_subject`
       CHECK (`client_id` IS NOT NULL OR `subject_key` IS NOT NULL)',
  'SELECT ''το chk_task_subject υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. Νεο UNIQUE και αφαιρεση του παλιου, στο ΙΔΙΟ ALTER.
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND INDEX_NAME   = 'uq_task_dedupe') = 0,
  'ALTER TABLE `4a_tasks`
     ADD UNIQUE KEY `uq_task_dedupe` (`task_code`, `dedupe_key`),
     DROP INDEX `uq_client_task_offer`',
  'SELECT ''το uq_task_dedupe υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- Ελεγχος
-- ---------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_tasks'
   AND COLUMN_NAME IN ('client_id','subject_key','country','dedupe_key');

SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols, NON_UNIQUE
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_tasks'
 GROUP BY INDEX_NAME, NON_UNIQUE;
