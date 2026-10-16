-- =====================================================================
--  rollback/2026-10-19b_ui_texts_fuel_week.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-19b: σβήνει ΜΟΝΟ τα 8 κλειδιά που πρόσθεσε.
--  Τα κείμενα του 2026-10-15b μένουν ως έχουν.
--
--  Πριν από αυτό, αν έχει γίνει push το frontend του Α3 ΚΑΙ η ενεργοποίηση
--  Α4, πρώτα το rollback του Α4: αλλιώς οι ενεργές εβδομάδες δείχνουν
--  [κλειδί]. Χωρίς ενεργοποίηση κανείς δεν τα διαβάζει.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('fuel_w_upload', 'fuel_w_upload_late', 'fuel_w_verify_wait', 'fuel_w_verify_now',
               'fuel_w_verify_late', 'fuel_w_done', 'fuel_verify_locked', 'fuel_verify_locked_none');

-- Αναμενόμενο: clients 2 · common 2 · tasks 14
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
