-- =====================================================================
--  rollback/2026-10-19b_ui_texts_fuel_week.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-19b: σβήνει ΜΟΝΟ τα 29 κλειδιά που πρόσθεσε.
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
               'fuel_w_verify_late', 'fuel_w_done', 'strip_l1', 'strip_l2', 'strip_l3', 'strip_m2',
               'strip_m3', 'strip_unassigned', 'strip_who', 'strip_wait_assign', 'strip_b3', 'strip_d1',
               'strip_upload_done', 'strip_d3', 'strip_when', 'strip_tip_locked', 'strip_tip_monday',
               'strip_tip_done', 'strip_notes_h', 'strip_notes_none', 'strip_note_to',
               'strip_menu_assign', 'strip_menu_hist', 'color_step_done', 'color_step_locked');

-- Αναμενόμενο: clients 2 · common 2 · tasks 14
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
