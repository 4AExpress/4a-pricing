-- =====================================================================
--  rollback/2026-10-22a_fuel_weekly_activate.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-22a: σβήνει ΟΛΕΣ τις γραμμές του 4a_task_steps
--  για το fuel_weekly (ο διακόπτης κλείνει: η κάρτα γίνεται ξανά ανενεργή,
--  το γενικό «Ολοκλήρωση» ξαναδουλεύει) και επαναφέρει allow_* = 1 στους
--  δύο τύπους (τιμή πριν το Α4: προεπιλογή των 12a / 19a, το Β3β του Α4
--  το επιβεβαιώνει).
--
--  ΤΑ ΓΕΓΟΝΟΤΑ ΜΕΝΟΥΝ: downloaded / uploaded / admin_override που γράφτηκαν
--  μετά το Α4 δεν σβήνονται (ιστορικό). Εργασία που έκλεισε από βήμα μένει
--  κλειστή. Εργασία στη μέση (π.χ. ① έγινε, ② όχι) γίνεται ξανά γενική
--  κάρτα με «Ολοκλήρωση».
--
--  Το frontend ΔΕΝ χρειάζεται επαναφορά: χωρίς βήματα δείχνει την παλιά ροή.
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM `4a_task_steps` WHERE `task_code` = 'fuel_weekly';

UPDATE `4a_task_types`
   SET `allow_na` = 1, `allow_release` = 1, `allow_reject` = 1, `allow_steal` = 1
 WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify');

-- Αναμενόμενο: 0
SELECT COUNT(*) AS `σύνολο_βημάτων` FROM `4a_task_steps`;

-- Αναμενόμενο: fuel_weekly 1 1 1 1 0 · fuel_weekly_verify 1 1 1 1 1
SELECT `code`, `allow_na`, `allow_release`, `allow_reject`, `allow_steal`, `close_from_week_start`
  FROM `4a_task_types` WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify') ORDER BY `code`;
