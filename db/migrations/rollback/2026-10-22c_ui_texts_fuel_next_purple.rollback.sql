-- =====================================================================
--  rollback/2026-10-22c_ui_texts_fuel_next_purple.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-22c: τα 4 χρώματα «ακολουθεί» ξανά στις τιμές
--  του 21a (γαλάζιο). Κανένα κλειδί δεν σβήνεται. Ο κώδικας δεν αλλάζει,
--  άρα δεν χρειάζεται καμία επαναφορά frontend.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'color_fs_next_bg',    'color', '#e2f1f4'),
  ('tasks',   'color_fs_next_line',  'color', '#4f9fb0'),
  ('tasks',   'color_fs_next_text',  'color', '#1d4f5a'),
  ('tasks',   'color_fs_next_num',   'color', '#3f7f8d')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

-- Αναμενόμενο: 4 γραμμές με τα χρώματα του 21a
SELECT `k`, `kind`, `text` FROM `4a_ui_texts`
 WHERE `scope` = 'tasks' AND `k` IN ('color_fs_next_bg', 'color_fs_next_line', 'color_fs_next_num', 'color_fs_next_text') ORDER BY `k`;
