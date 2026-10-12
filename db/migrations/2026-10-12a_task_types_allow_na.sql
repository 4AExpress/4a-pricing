-- =====================================================================
--  2026-10-12a_task_types_allow_na.sql
--
--  ΦΑΣΗ 5, Κ1α. «Δεν εφαρμόζεται» ανά τύπο εργασίας, στη βάση.
--
--  4a_task_types.allow_na   1 = το «Δεν εφαρμόζεται» επιτρέπεται (όπως
--                           σήμερα, για ΟΛΟΥΣ τους τύπους)
--                           0 = ο server απαντά 409 στο na
--
--  ΧΩΡΙΣ UPDATE: όλοι οι τύποι μένουν 1, άρα τίποτα δεν αλλάζει στη
--  σημερινή εμπειρία. Το 0 για τους τύπους επίναυλου μπαίνει ΜΟΝΟ με το
--  migration ενεργοποίησης (Κ4α), μετά από έγκριση.
--
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αγγίζεται.
--
--  Τρέχει ΠΡΙΝ από το SCP του api/tasks_lib.php που τη διαβάζει. Ο κώδικας
--  αντέχει και την απουσία της (θεωρεί 1).
--
--  Αντίστροφο: rollback/2026-10-12a_task_types_allow_na.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'allow_na') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `allow_na` TINYINT(1) NOT NULL DEFAULT 1
       COMMENT ''1 = επιτρέπεται το «Δεν εφαρμόζεται», 0 = 409 στο na''',
  'SELECT ''η στηλη 4a_task_types.allow_na υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME  = 'allow_na';

-- Αναμενόμενο: ΟΛΟΙ οι τύποι 1.
SELECT `allow_na`, COUNT(*) AS `τύποι`
  FROM `4a_task_types` GROUP BY `allow_na`;
