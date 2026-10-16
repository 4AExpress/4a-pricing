-- =====================================================================
--  2026-10-19c_task_types_close_from_week.sql
--
--  ΦΑΣΗ Α, Δ-Α8 (απόφαση 16/10). Η επαλήθευση του επίναυλου ΔΕΝ κλείνει
--  πριν από τις 00:00 ώρα Αθήνας της Δευτέρας της εβδομάδας ισχύος
--  (week_start του payload). Ο server απαντά 409 «η επαλήθευση ανοίγει
--  Δευ dd/mm» στο done, στο na και σε κάθε κλείσιμο από βήμα.
--
--  4a_task_types.close_from_week_start   0 = όπως σήμερα (προεπιλογή)
--                                        1 = ο κανόνας του Δ-Α8
--
--  Ο κανόνας ενεργοποιείται ΑΜΕΣΩΣ για το fuel_weekly_verify, ΧΩΡΙΣ να
--  περιμένει το Α4: δεν εξαρτάται από την κάρτα βημάτων. Οι ανοιχτές
--  εργασίες verify με week_start που πέρασε δεν επηρεάζονται.
--
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αγγίζεται.
--
--  Τρέχει ΠΡΙΝ από το SCP του api/tasks_steps.php που τη διαβάζει. Ο κώδικας
--  αντέχει και την απουσία της (θεωρεί 0).
--
--  Αντίστροφο: rollback/2026-10-19c_task_types_close_from_week.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'close_from_week_start') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `close_from_week_start` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = κλείνει μόνο από Δευ 00:00 (Αθήνα) του week_start, αλλιώς 409''',
  'SELECT ''η στηλη 4a_task_types.close_from_week_start υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ΜΟΝΟ το fuel_weekly_verify, με φρουρό στην τρέχουσα τιμή.
UPDATE `4a_task_types` SET `close_from_week_start` = 1
 WHERE `code` = 'fuel_weekly_verify' AND `close_from_week_start` = 0;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: tinyint(1), NO, 0
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME  = 'close_from_week_start';

-- Αναμενόμενο: ΜΟΝΟ fuel_weekly_verify
SELECT `code`, `close_from_week_start`
  FROM `4a_task_types` WHERE `close_from_week_start` = 1 ORDER BY `code`;
