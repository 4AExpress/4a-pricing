-- =====================================================================
--  Αντιστροφο του 2026-10-14a_tasks_country_collation.sql
--
--  Επαναφερει utf8mb4_unicode_ci στις δυο στηλες country. ΠΡΟΣΟΧΗ: με
--  αυτο ξαναγυριζει το 500 για καθε μη-admin με πεδιο χωρας.
--  Τυπος, NULL, DEFAULT, COMMENT ιδια. Επαναληψιμο.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA   = DATABASE()
                   AND TABLE_NAME     = '4a_tasks'
                   AND COLUMN_NAME    = 'country'
                   AND COLUMN_TYPE    = 'enum(''GR'',''CY'',''BOTH'')'
                   AND IS_NULLABLE    = 'YES'
                   AND COLUMN_DEFAULT IS NULL
                   AND COLLATION_NAME <> 'utf8mb4_unicode_ci') = 1,
  'ALTER TABLE `4a_tasks`
     MODIFY COLUMN `country` ENUM(''GR'',''CY'',''BOTH'')
       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
       COMMENT ''χωρα εργασιας χωρις πελατη. NULL = απο τον πελατη''',
  'SELECT ''4a_tasks.country: ηδη unicode_ci ή απροσδοκητος τυπος'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA   = DATABASE()
                   AND TABLE_NAME     = '4a_user_task_skills'
                   AND COLUMN_NAME    = 'country'
                   AND COLUMN_TYPE    = 'enum(''GR'',''CY'',''BOTH'')'
                   AND IS_NULLABLE    = 'NO'
                   AND COLUMN_DEFAULT = 'BOTH'
                   AND COLLATION_NAME <> 'utf8mb4_unicode_ci') = 1,
  'ALTER TABLE `4a_user_task_skills`
     MODIFY COLUMN `country` ENUM(''GR'',''CY'',''BOTH'')
       CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''BOTH''',
  'SELECT ''4a_user_task_skills.country: ηδη unicode_ci ή απροσδοκητος τυπος'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'country'
   AND TABLE_NAME IN ('4a_tasks', '4a_user_task_skills')
 ORDER BY TABLE_NAME;
