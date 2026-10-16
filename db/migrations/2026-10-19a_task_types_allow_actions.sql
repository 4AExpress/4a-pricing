-- =====================================================================
--  2026-10-19a_task_types_allow_actions.sql
--
--  ΦΑΣΗ Α, Α2. «Αφήνω», «Απόρριψη», «Αναλαμβάνω» ανά τύπο εργασίας, στη
--  βάση, στο μοτίβο του allow_na (2026-10-12a).
--
--  4a_task_types.allow_release   1 = επιτρέπεται το «Αφήνω» (όπως σήμερα)
--  4a_task_types.allow_reject    1 = επιτρέπεται η «Απόρριψη»
--  4a_task_types.allow_steal     1 = επιτρέπεται το «Αναλαμβάνω»
--                                0 = ο server απαντά 409 (Δ-Α1)
--
--  ΧΩΡΙΣ UPDATE: όλοι οι τύποι μένουν 1, άρα τίποτα δεν αλλάζει στη
--  σημερινή εμπειρία. Το 0 για το fuel_weekly μπαίνει ΜΟΝΟ με το migration
--  ενεργοποίησης (Α4).
--
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αγγίζεται.
--
--  Τρέχει ΠΡΙΝ από το SCP του api/tasks_lib.php που τις διαβάζει. Ο κώδικας
--  αντέχει και την απουσία τους (θεωρεί 1).
--
--  Αντίστροφο: rollback/2026-10-19a_task_types_allow_actions.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_release') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `allow_release` TINYINT(1) NOT NULL DEFAULT 1
       COMMENT ''1 = επιτρέπεται το «Αφήνω», 0 = 409 στο release''',
  'SELECT ''η στηλη 4a_task_types.allow_release υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_reject') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `allow_reject` TINYINT(1) NOT NULL DEFAULT 1
       COMMENT ''1 = επιτρέπεται η «Απόρριψη», 0 = 409 στο reject''',
  'SELECT ''η στηλη 4a_task_types.allow_reject υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_steal') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `allow_steal` TINYINT(1) NOT NULL DEFAULT 1
       COMMENT ''1 = επιτρέπεται το «Αναλαμβάνω», 0 = 409 στο steal''',
  'SELECT ''η στηλη 4a_task_types.allow_steal υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 3 γραμμές, tinyint(1), NO, 1
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME IN ('allow_release', 'allow_reject', 'allow_steal')
 ORDER BY `COLUMN_NAME`;

-- Αναμενόμενο: ΜΙΑ γραμμή 1 · 1 · 1 με όλους τους τύπους.
SELECT `allow_release`, `allow_reject`, `allow_steal`, COUNT(*) AS `τύποι`
  FROM `4a_task_types` GROUP BY `allow_release`, `allow_reject`, `allow_steal`;
