-- =====================================================================
--  2026-10-22c_ui_texts_fuel_next_purple.sql
--
--  ΚΑΡΤΑ ΕΠΙΝΑΥΛΟΥ (απόφαση Απόστολου 21/10): το «ακολουθεί / ΠΕΡΙΜΕΝΕΙ»
--  (γραμμές, κύκλοι, γραμμή μπάρας) από γαλάζιο σε ΛΙΛΑ, όπως οι γραμμές
--  του CMS. ΜΟΝΟ τα 4 χρώματα του 21a, καμία αλλαγή κώδικα:
--     color_fs_next_bg    #e2f1f4 -> #e3d9ef  φόντο γραμμής
--     color_fs_next_line  #4f9fb0 -> #8e6fc4  διακεκομμένο περίγραμμα γραμμής,
--                                             περίγραμμα κύκλου και ετικέτας,
--                                             γραμμή μπάρας που δεν ολοκληρώθηκε
--     color_fs_next_text  #1d4f5a -> #4b2f7a  κείμενο
--     color_fs_next_num   #3f7f8d -> #6b4fa3  νούμερο του κύκλου
--  Αντίθεση: κείμενο σε φόντο γραμμής 7.77 · σε λευκό 10.57 · σε #fffef0
--  10.41 · νούμερο σε λευκό 6.42 (όλα ≥ 4.5). Γραφικά: #8e6fc4 σε λευκό
--  4.02, σε #fffef0 3.96 (≥ 3).
--  Το μωβ της κατάστασης open (4a_task_statuses) και της ετικέτας
--  ΣΥΣΤΗΜΑ ΔΕΝ αλλάζει.
--
--  Αντικατάσταση τιμής (ON DUPLICATE KEY UPDATE text = VALUES(text)): τα
--  4 κλειδιά υπάρχουν ήδη από το 21a. ΠΡΟΣΟΧΗ: γράφει ΠΑΝΩ από την τιμή
--  της βάσης. Κανένα νέο κλειδί, κανένα DDL.
--
--  Αντίστροφο: rollback/2026-10-22c_ui_texts_fuel_next_purple.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'color_fs_next_bg',    'color', '#e3d9ef'),
  ('tasks',   'color_fs_next_line',  'color', '#8e6fc4'),
  ('tasks',   'color_fs_next_text',  'color', '#4b2f7a'),
  ('tasks',   'color_fs_next_num',   'color', '#6b4fa3')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 4 γραμμές με τα νέα χρώματα
SELECT `k`, `kind`, `text` FROM `4a_ui_texts`
 WHERE `scope` = 'tasks' AND `k` IN ('color_fs_next_bg', 'color_fs_next_line', 'color_fs_next_num', 'color_fs_next_text') ORDER BY `k`;

-- Αναμενόμενο (αμετάβλητο): clients 2 · common 3 · tasks 79
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
