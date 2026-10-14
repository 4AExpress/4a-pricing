-- =====================================================================
--  2026-10-14b_task_types_due_rule.sql
--
--  ΦΑΣΗ 5, Π3. ΠΡΟΘΕΣΜΙΑ ΑΝΑ ΤΥΠΟ ΕΡΓΑΣΙΑΣ, ΣΤΗ ΒΑΣΗ.
--
--  Μέχρι σήμερα το fuel_weekly_due_at() έδινε ΚΑΙ στους δύο τύπους την
--  Παρασκευή 17:00 πριν το week_start, γραμμένη στον κώδικα. Απόφαση 12/10
--  (phase5_plan.md, ερώτημα 33): το verify λήγει Δευτέρα 12:00 (Αθήνα) της
--  εβδομάδας ισχύος.
--
--  4a_task_types.due_offset_days   μέρες από το week_start (Δευτέρα)
--                                  -3 = Παρασκευή πριν, 0 = η ίδια Δευτέρα
--  4a_task_types.due_time          ώρα Αθήνας, όπως το 4a_tasks.due_at
--                                  NULL σε ένα από τα δύο = κανένας κανόνας:
--                                  ο κώδικας ΔΕΝ δημιουργεί την εργασία
--                                  (σφάλμα στο log του cron), αντί να
--                                  μαντέψει προθεσμία.
--
--  fuel_weekly         -3 / 19:00   απόφαση 09/10 (Ε4): Παρασκευή 19:00.
--                                   Οι #76 / #87 κρατούν τις 17:00 (Ο1).
--  fuel_weekly_verify   0 / 12:00   νέα
--
--  Και όρια χρώματος του verify (4a_task_types.due_warn_hours /
--  due_urgent_hours, από το 08c), απόφαση 14/10: 12 / 3. Πορτοκαλί από
--  Δευ 00:00 (τότε ανοίγει η επαλήθευση), ΕΠΕΙΓΟΝ από Δευ 09:00. Ως τώρα
--  NULL, γιατί η προθεσμία του δεν ήταν δική του.
--
--  Ισχύει ΜΟΝΟ για εργασίες που δημιουργούνται μετά το deploy. ΚΑΜΙΑ
--  εργασία (4a_tasks) δεν αγγίζεται: η εβδομάδα 19/10 κρατά ό,τι πήρε.
--
--  Τρέχει ΠΡΙΝ από το SCP των api/fuel_lib.php και api/fuel_weekly.php
--  που διαβάζουν τις στήλες. Ο παλιός κώδικας δεν τις διαβάζει.
--
--  Αντίστροφο: rollback/2026-10-14b_task_types_due_rule.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. due_offset_days ────────────────────────────────────────────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_offset_days') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `due_offset_days` INT NULL DEFAULT NULL
       COMMENT ''προθεσμια: μερες από το week_start (Δευτερα), -3 = Παρασκευη πριν, NULL = κανενας κανονας''',
  'SELECT ''η στηλη due_offset_days υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. due_time ───────────────────────────────────────────────────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_time') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `due_time` TIME NULL DEFAULT NULL
       COMMENT ''προθεσμια: ωρα Αθηνας, NULL = κανενας κανονας''
       AFTER `due_offset_days`',
  'SELECT ''η στηλη due_time υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. Κανόνες ────────────────────────────────────────────────────────
UPDATE `4a_task_types` SET `due_offset_days` = -3, `due_time` = '19:00:00' WHERE `code` = 'fuel_weekly';
UPDATE `4a_task_types` SET `due_offset_days` =  0, `due_time` = '12:00:00' WHERE `code` = 'fuel_weekly_verify';

-- ── 4. Όρια χρώματος του verify (στήλες του 08c, υπάρχουν ήδη) ────────
UPDATE `4a_task_types` SET `due_warn_hours` = 12, `due_urgent_hours` = 3 WHERE `code` = 'fuel_weekly_verify';

-- ── 5. Επαλήθευση ─────────────────────────────────────────────────────
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_types'
   AND COLUMN_NAME IN ('due_offset_days', 'due_time')
 ORDER BY `ORDINAL_POSITION`;

-- Αναμενόμενο: fuel_weekly -3 19:00:00 24 4 · fuel_weekly_verify 0 12:00:00 12 3 ·
-- όλοι οι άλλοι τύποι NULL NULL NULL NULL.
SELECT `code`, `due_offset_days`, `due_time`, `due_warn_hours`, `due_urgent_hours`
  FROM `4a_task_types` ORDER BY `sort_order`, `code`;
