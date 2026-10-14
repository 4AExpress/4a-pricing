-- =====================================================================
--  2026-10-14a_tasks_country_collation.sql
--
--  HTTP 500 ΣΕ ΚΑΘΕ ΜΗ-ADMIN ΜΕ ΠΕΔΙΟ ΧΩΡΑΣ (GR, CY, NONE) στη λιστα
--  εργασιων (mine, queue) και στο σημα, απο το deploy της 07/10.
--
--  Αιτια: το TASKS_COUNTRY_SQL = COALESCE(t.country, c.country) ενωνει
--  δυο στηλες με διαφορετικο collation:
--      4a_tasks.country     utf8mb4_unicode_ci   (migration 2026-10-07e)
--      4a_clients.country   utf8mb4_0900_ai_ci
--  Το αποτελεσμα παιρνει utf8mb4_bin με derivation NONE, και καθε
--  συγκριση του (= 'BOTH', IN (...), s.country IN (...)) αποτυγχανει
--  με 1267 / 1270. Ο admin δεν εχει φιλτρο χωρας, γι' αυτο δουλευει.
--
--  Διορθωση: οι στηλες country των ΠΙΝΑΚΩΝ ΕΡΓΑΣΙΩΝ παιρνουν το
--  collation του 4a_clients.country:
--      4a_tasks.country              -> utf8mb4_0900_ai_ci
--      4a_user_task_skills.country   -> utf8mb4_0900_ai_ci
--  (η δευτερη χρειαζεται για το s.country IN (COALESCE(...), 'BOTH')).
--  Ο 4a_clients ΔΕΝ αγγιζεται.
--
--  Τυπος, ENUM, NULL, DEFAULT και COMMENT μενουν ΑΚΡΙΒΩΣ ιδια. Το ALTER
--  τρεχει ΜΟΝΟ αν ο τυπος ειναι ο αναμενομενος και το collation
--  διαφερει. Αλλιως ΠΑΡΑΛΕΙΨΗ με μηνυμα. Επαναληψιμο.
--
--  Αντιστροφο: rollback/2026-10-14a_tasks_country_collation.rollback.sql
--
--  MySQL 8.4. Καμια συνταξη MariaDB.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 0. Πριν
-- ---------------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       COLLATION_NAME, COLUMN_COMMENT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'country'
   AND TABLE_NAME IN ('4a_tasks', '4a_user_task_skills', '4a_clients')
 ORDER BY TABLE_NAME;

-- ---------------------------------------------------------------------
-- 1. 4a_tasks.country: ENUM('GR','CY','BOTH') NULL, DEFAULT NULL, με σχολιο
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA   = DATABASE()
                   AND TABLE_NAME     = '4a_tasks'
                   AND COLUMN_NAME    = 'country'
                   AND COLUMN_TYPE    = 'enum(''GR'',''CY'',''BOTH'')'
                   AND IS_NULLABLE    = 'YES'
                   AND COLUMN_DEFAULT IS NULL
                   AND COLLATION_NAME <> 'utf8mb4_0900_ai_ci') = 1,
  'ALTER TABLE `4a_tasks`
     MODIFY COLUMN `country` ENUM(''GR'',''CY'',''BOTH'')
       CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL DEFAULT NULL
       COMMENT ''χωρα εργασιας χωρις πελατη. NULL = απο τον πελατη''',
  'SELECT ''4a_tasks.country: ηδη 0900_ai_ci ή απροσδοκητος τυπος, καμια αλλαγη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. 4a_user_task_skills.country: ENUM('GR','CY','BOTH') NOT NULL DEFAULT 'BOTH'
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA   = DATABASE()
                   AND TABLE_NAME     = '4a_user_task_skills'
                   AND COLUMN_NAME    = 'country'
                   AND COLUMN_TYPE    = 'enum(''GR'',''CY'',''BOTH'')'
                   AND IS_NULLABLE    = 'NO'
                   AND COLUMN_DEFAULT = 'BOTH'
                   AND COLLATION_NAME <> 'utf8mb4_0900_ai_ci') = 1,
  'ALTER TABLE `4a_user_task_skills`
     MODIFY COLUMN `country` ENUM(''GR'',''CY'',''BOTH'')
       CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT ''BOTH''',
  'SELECT ''4a_user_task_skills.country: ηδη 0900_ai_ci ή απροσδοκητος τυπος, καμια αλλαγη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Μετα: ιδιος τυπος/NULL/DEFAULT/COMMENT, νεο collation
-- ---------------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       COLLATION_NAME, COLUMN_COMMENT
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'country'
   AND TABLE_NAME IN ('4a_tasks', '4a_user_task_skills', '4a_clients')
 ORDER BY TABLE_NAME;

-- ---------------------------------------------------------------------
-- 4. Οι συγκρισεις που επεφταν (1267/1270). Πρεπει να τρεχουν χωρις σφαλμα.
-- ---------------------------------------------------------------------
SELECT COUNT(*) AS `φιλτρο_χωρας_ok`
  FROM `4a_tasks` t
  LEFT JOIN `4a_clients` c ON c.`id` = t.`client_id`
 WHERE COALESCE(t.`country`, c.`country`) = 'BOTH'
    OR COALESCE(t.`country`, c.`country`) IN ('GR');

SELECT COUNT(*) AS `δεξιοτητα_ok`
  FROM `4a_tasks` t
  LEFT JOIN `4a_clients` c ON c.`id` = t.`client_id`
 WHERE EXISTS (SELECT 1 FROM `4a_user_task_skills` s
                WHERE s.`task_code` = t.`task_code`
                  AND s.`country` IN (COALESCE(t.`country`, c.`country`), 'BOTH'));
