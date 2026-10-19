-- =====================================================================
--  rollback/2026-10-21a_ui_texts_step_done_mark.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-21a: σβήνει ΜΟΝΟ το color_step_done_mark. Η οθόνη
--  γυρνά στο χρώμα-εφεδρεία του CSS (ίδιο). Κανένα άλλο κείμενο δεν αγγίζεται.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM `4a_ui_texts` WHERE `scope` = 'tasks' AND `k` = 'color_step_done_mark';

-- Αναμενόμενο: clients 2 · common 2 · tasks 43
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
