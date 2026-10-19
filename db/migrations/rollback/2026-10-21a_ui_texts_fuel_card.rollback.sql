-- =====================================================================
--  rollback/2026-10-21a_ui_texts_fuel_card.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-21a: σβήνει ΜΟΝΟ τα 35 νέα κλειδιά και
--  επαναφέρει τα 6 κείμενα κεφαλίδας στις τιμές του 2026-10-19b.
--
--  Πριν από αυτό, αν η κάρτα είναι ενεργή (Α4) με το frontend της 21a:
--  πρώτα το frontend, αλλιώς η οθόνη δείχνει [κλειδί].
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('fs_h1', 'fs_b1', 'fs_d1', 'fs_again', 'fs_h2', 'fs_b2', 'fs_d2', 'fs_h3', 'fs_b3',
               'fs_d3', 'fs_tag_now', 'fs_tag_wait', 'fs_tag_done', 'fs_tag_wrong', 'fs_now_bar',
               'fs_next_bar', 'fs_next_row', 'fs_after', 'fuel_w_download', 'fuel_w_wrong',
               'color_fs_bar_bg', 'color_fs_ink', 'color_fs_done_dot', 'color_fs_done_line',
               'color_fs_mark', 'color_fs_done_cap', 'color_fs_done_bg', 'color_fs_done_text',
               'color_fs_next_line', 'color_fs_next_num', 'color_fs_next_text', 'color_fs_next_bg',
               'color_fs_wrong', 'color_fs_wrong_bg', 'color_fs_btn');

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'fuel_w_upload',       'text',  '◷ ① Ανέβασμα στο CMS · τώρα · έως {due}'),
  ('tasks',   'fuel_w_upload_late',  'text',  '⚠ ① Ανέβασμα στο CMS · εκπρόθεσμο από {due}'),
  ('tasks',   'fuel_w_verify_wait',  'text',  '⏳ ② Επαλήθευση · από {from} · έως {time}'),
  ('tasks',   'fuel_w_verify_now',   'text',  '◷ ② Επαλήθευση · τώρα · έως {time}'),
  ('tasks',   'fuel_w_verify_late',  'text',  '⚠ ② Επαλήθευση · εκπρόθεσμη από {due}'),
  ('tasks',   'fuel_w_done',         'text',  '✓ Σε ισχύ · ανέβηκε και επαληθεύτηκε')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

-- Αναμενόμενο: clients 2 · common 2 · tasks 43
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
