-- =====================================================================
--  2026-10-21a_ui_texts_fuel_card.sql
--
--  ΚΑΡΤΑ ΕΠΙΝΑΥΛΟΥ (απόφαση 19/10, αντικαθιστά τη λωρίδα της 16/10):
--  μπάρα προόδου ① → ② → ③ και γραμμές βημάτων σε σταθερή σειρά (③ πάνω,
--  ① κάτω). Όλα τα κείμενα και τα χρώματα στον 4a_ui_texts (scope tasks).
--
--  1. 35 ΝΕΑ κλειδιά στο scope tasks, και 1 ΚΟΙΝΟ (scope common):
--     btn_fuel_cms_download  το κουμπί της ΠΑΛΙΑΣ γραμμής επίναυλου, ΙΔΙΟ κείμενο
--                            με το σημερινό live («⬇ Λήψη αρχείου CMS»), που
--                            έφυγε από τον κώδικα (απόφαση 19/10). Το διαβάζει
--                            ΗΔΗ η οθόνη χωρίς βήματα: τρέχει ΠΡΙΝ από το push.
--     fs_*            οδηγίες, κουμπιά, «έγινε», ετικέτες, μπάρα
--     fuel_w_download κεφαλίδα όταν τρέχει το ①
--     fuel_w_wrong    κεφαλίδα μετά από λάθος επαλήθευση (επαληθευτής)
--     color_fs_*      χρώματα (kind = color, ΜΟΝΟ #rrggbb). Κάθε κείμενο ή
--                     ✓ πάνω σε φόντο ≥ 4.5:1. Πέντε τιμές της προδιαγραφής
--                     δεν περνούσαν και έγιναν το κοντινότερο που περνά:
--                       ✓ #2e7d32 -> #2b742f  (σε #e4e4e2: 4.03 -> 4.53)
--                       «πότε» #8a8c8e -> #737577 (σε #fffef0: 3.32 -> 4.56)
--                       αριθμός «ακολουθεί» #4f9fb0 -> #3f7f8d (σε λευκό: 3.04 -> 4.53)
--                       γραμμή «έγινε»: όχι opacity 0.5 (κείμενο 2.91, ✓ 1.85)·
--                       κείμενο #646667 σε #e9e9e7 (4.75) και σε #e4e4e2 (4.53)
--  2. UPDATE 6 κειμένων της κεφαλίδας (2026-10-19b, ήδη στη βάση): αρίθμηση
--     ② Ανέβασμα / ③ Επαλήθευση όπως η κάρτα, και «Τώρα: … · έως …».
--     ΠΡΟΣΟΧΗ: γράφει ΠΑΝΩ από την τιμή της βάσης (και αν άλλαξε από την οθόνη).
--
--  ΚΑΝΕΝΑΣ δεν τα διαβάζει πριν από την ενεργοποίηση (Α4): η κάρτα και η
--  κεφαλίδα «Τώρα:» εμφανίζονται ΜΟΝΟ όταν η ρίζα της εβδομάδας έχει βήματα.
--
--  Αντίστροφο: rollback/2026-10-21a_ui_texts_fuel_card.rollback.sql (σβήνει
--  τα νέα, επαναφέρει τα 6 κείμενα του 19b).
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'fs_h1',               'text',  'Κατέβασε το αρχείο για το CMS.'),
  ('tasks',   'fs_b1',               'text',  '⬇ Λήψη αρχείου'),
  ('tasks',   'fs_d1',               'text',  'Κατέβηκε · {at} · {name}'),
  ('tasks',   'fs_again',            'text',  '⬇ ξανά'),
  ('tasks',   'fs_h2',               'text',  'Μπες στο CMS → Fuel Charge Import → ανέβασε το αρχείο. Μετά πάτα το κουμπί.'),
  ('tasks',   'fs_b2',               'text',  'Δηλώνω: το ανέβασα στο CMS'),
  ('tasks',   'fs_d2',               'text',  'Ανέβηκε στο CMS · {at} · {name}'),
  ('tasks',   'fs_h3',               'text',  'Έλεγξε τις τιμές στο Price Calculator του CMS και διάλεξέ τες στον επαληθευτή.'),
  ('tasks',   'fs_b3',               'text',  '🔍 Άνοιξε τον επαληθευτή'),
  ('tasks',   'fs_d3',               'text',  'Επαληθεύτηκε · {at} · {name}'),
  ('tasks',   'fs_tag_now',          'text',  '▶ ΤΩΡΑ'),
  ('tasks',   'fs_tag_wait',         'text',  '🔒 ΠΕΡΙΜΕΝΕΙ'),
  ('tasks',   'fs_tag_done',         'text',  'ΕΓΙΝΕ'),
  ('tasks',   'fs_tag_wrong',        'text',  '✗ ΛΑΘΟΣ'),
  ('tasks',   'fs_now_bar',          'text',  'ΤΩΡΑ · έως {due}'),
  ('tasks',   'fs_next_bar',         'text',  '→ {name} · {from}'),
  ('tasks',   'fs_next_row',         'text',  '→ {name} · ανοίγει {from} · έως {due}'),
  ('tasks',   'fs_after',            'text',  'όταν γίνει το {n}'),
  ('tasks',   'fuel_w_download',     'text',  'Τώρα: ① Λήψη αρχείου · έως {due}'),
  ('tasks',   'fuel_w_wrong',        'text',  '✗ Λάθος στην ③ Επαλήθευση'),
  ('tasks',   'color_fs_bar_bg',     'color', '#fffef0'),
  ('tasks',   'color_fs_ink',        'color', '#1e2022'),
  ('tasks',   'color_fs_done_dot',   'color', '#e4e4e2'),
  ('tasks',   'color_fs_done_line',  'color', '#6b6e70'),
  ('tasks',   'color_fs_mark',       'color', '#2b742f'),
  ('tasks',   'color_fs_done_cap',   'color', '#737577'),
  ('tasks',   'color_fs_done_bg',    'color', '#e9e9e7'),
  ('tasks',   'color_fs_done_text',  'color', '#646667'),
  ('tasks',   'color_fs_next_line',  'color', '#4f9fb0'),
  ('tasks',   'color_fs_next_num',   'color', '#3f7f8d'),
  ('tasks',   'color_fs_next_text',  'color', '#1d4f5a'),
  ('tasks',   'color_fs_next_bg',    'color', '#e2f1f4'),
  ('tasks',   'color_fs_wrong',      'color', '#cc0000'),
  ('tasks',   'color_fs_wrong_bg',   'color', '#fde8e8'),
  ('tasks',   'color_fs_btn',        'color', '#1f5fbf'),
  ('common',  'btn_fuel_cms_download',  'text',  '⬇ Λήψη αρχείου CMS')
ON DUPLICATE KEY UPDATE `k` = `k`;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'fuel_w_upload',       'text',  'Τώρα: ② Ανέβασμα στο CMS · έως {due}'),
  ('tasks',   'fuel_w_upload_late',  'text',  '⚠ ② Ανέβασμα στο CMS · εκπρόθεσμο από {due}'),
  ('tasks',   'fuel_w_verify_wait',  'text',  'Επόμενο: ③ Επαλήθευση · ανοίγει {from} · έως {time}'),
  ('tasks',   'fuel_w_verify_now',   'text',  'Τώρα: ③ Επαλήθευση · έως {time}'),
  ('tasks',   'fuel_w_verify_late',  'text',  '⚠ ③ Επαλήθευση · εκπρόθεσμη από {due}'),
  ('tasks',   'fuel_w_done',         'text',  '✓ Ολοκληρώθηκαν και τα 3 βήματα')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 35
SELECT COUNT(*) AS `νέα_κλειδιά`
  FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('fs_h1', 'fs_b1', 'fs_d1', 'fs_again', 'fs_h2', 'fs_b2', 'fs_d2', 'fs_h3', 'fs_b3',
               'fs_d3', 'fs_tag_now', 'fs_tag_wait', 'fs_tag_done', 'fs_tag_wrong', 'fs_now_bar',
               'fs_next_bar', 'fs_next_row', 'fs_after', 'fuel_w_download', 'fuel_w_wrong',
               'color_fs_bar_bg', 'color_fs_ink', 'color_fs_done_dot', 'color_fs_done_line',
               'color_fs_mark', 'color_fs_done_cap', 'color_fs_done_bg', 'color_fs_done_text',
               'color_fs_next_line', 'color_fs_next_num', 'color_fs_next_text', 'color_fs_next_bg',
               'color_fs_wrong', 'color_fs_wrong_bg', 'color_fs_btn');

-- Αναμενόμενο: 6 γραμμές με τα νέα κείμενα
SELECT `k`, `text` FROM `4a_ui_texts`
 WHERE `scope` = 'tasks' AND `k` IN ('fuel_w_upload', 'fuel_w_upload_late', 'fuel_w_verify_wait', 'fuel_w_verify_now',
               'fuel_w_verify_late', 'fuel_w_done') ORDER BY `k`;

-- Αναμενόμενο: 1
SELECT COUNT(*) AS `κοινό_κλειδί` FROM `4a_ui_texts` WHERE `scope` = 'common' AND `k` = 'btn_fuel_cms_download';

-- Αναμενόμενο (μετά τα 15b και 19b): clients 2 · common 3 · tasks 78
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
