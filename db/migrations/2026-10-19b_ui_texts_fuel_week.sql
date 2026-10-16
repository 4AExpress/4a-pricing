-- =====================================================================
--  2026-10-19b_ui_texts_fuel_week.sql
--
--  ΦΑΣΗ Α, Α2/Α3. Νέα κείμενα οθόνης στον 4a_ui_texts (scope tasks) για
--  την εβδομάδα επίναυλου:
--    fuel_w_*      ενδείξεις κεφαλίδας (ΤΙ · ΑΠΟ ΠΟΤΕ · ΕΩΣ ΠΟΤΕ),
--                  εγκεκριμένες 16/10 (PhaseA_plan §δ.2)
--    strip_*       η λωρίδα βημάτων ① → ② → ③ (απόφαση 16/10, Πρόταση Β)
--    color_step_*  χρώματα της λωρίδας (kind = color, ΜΟΝΟ #rrggbb):
--                  έγινε σκούρο γκρι, κλειδωμένο ανοιχτό γκρι. Το τρέχον
--                  βήμα παίρνει το color_open_head του 2026-10-15b.
--
--  ΚΑΝΕΝΑΣ δεν τα διαβάζει πριν από την ενεργοποίηση (Α4): το frontend τα
--  δείχνει ΜΟΝΟ όταν η ρίζα της εβδομάδας έχει βήμα.
--
--  Τιμές από τα δεδομένα, όχι από τον κώδικα:
--    {due}   το due_at της εργασίας, π.χ. Παρ 23/10 19:00
--    {from}  η Δευτέρα του week_start, π.χ. Δευ 26/10
--    {time}  η ώρα του due_at του verify, π.χ. 17:00
--    {date}  η Δευτέρα του week_start ως ηη/μμ, π.χ. 26/10
--    {name}  ανάδοχος, ή όποιος έκανε το βήμα, ή παραλήπτης ειδοποίησης
--    {at}    πότε έγινε το βήμα, π.χ. Παρ 23/10 18:12
--    {n}     το βήμα στο μενού, ① ή ③
--
--  Προϋπόθεση: ο πίνακας του 2026-10-15b. Idempotent: κείμενο που υπάρχει
--  ήδη ΔΕΝ ξαναγράφεται.
--
--  Αντίστροφο: rollback/2026-10-19b_ui_texts_fuel_week.rollback.sql
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'fuel_w_upload',             'text',  '◷ ① Ανέβασμα στο CMS · τώρα · έως {due}'),
  ('tasks',   'fuel_w_upload_late',        'text',  '⚠ ① Ανέβασμα στο CMS · εκπρόθεσμο από {due}'),
  ('tasks',   'fuel_w_verify_wait',        'text',  '⏳ ② Επαλήθευση · από {from} · έως {time}'),
  ('tasks',   'fuel_w_verify_now',         'text',  '◷ ② Επαλήθευση · τώρα · έως {time}'),
  ('tasks',   'fuel_w_verify_late',        'text',  '⚠ ② Επαλήθευση · εκπρόθεσμη από {due}'),
  ('tasks',   'fuel_w_done',               'text',  '✓ Σε ισχύ · ανέβηκε και επαληθεύτηκε'),
  ('tasks',   'strip_l1',                  'text',  '① Λήψη'),
  ('tasks',   'strip_l2',                  'text',  '② Ανέβασμα στο CMS'),
  ('tasks',   'strip_l3',                  'text',  '③ Επαλήθευση'),
  ('tasks',   'strip_m2',                  'text',  'έως {due}'),
  ('tasks',   'strip_m3',                  'text',  '{name} · {from} 00:00–{time}'),
  ('tasks',   'strip_unassigned',          'text',  'αδιάθετο'),
  ('tasks',   'strip_who',                 'text',  'Το κάνει: {name}'),
  ('tasks',   'strip_wait_assign',         'text',  'Περιμένει ανάθεση'),
  ('tasks',   'strip_b3',                  'text',  '🔍 Επαλήθευση'),
  ('tasks',   'strip_d1',                  'text',  '✓ Κατέβηκε · ⬇ ξανά'),
  ('tasks',   'strip_d2',                  'text',  '✓ Ολοκληρώθηκε'),
  ('tasks',   'strip_d3',                  'text',  '✓ Επαληθεύτηκε'),
  ('tasks',   'strip_when',                'text',  '{at} · {name}'),
  ('tasks',   'strip_tip_locked',          'text',  'Πρώτα το προηγούμενο βήμα'),
  ('tasks',   'strip_tip_monday',          'text',  'Ανοίγει Δευ {date} 00:00'),
  ('tasks',   'strip_tip_done',            'text',  'Completed — Ολοκληρώθηκε, δεν ξαναγίνεται'),
  ('tasks',   'strip_notes_h',             'text',  '📧 Ειδοποιήσεις που στάλθηκαν'),
  ('tasks',   'strip_notes_none',          'text',  'Καμία ακόμα'),
  ('tasks',   'strip_note_to',             'text',  'προς {name}'),
  ('tasks',   'strip_menu_assign',         'text',  'Ανάθεση {n}…'),
  ('tasks',   'strip_menu_hist',           'text',  'ιστορικό {n}'),
  ('tasks',   'color_step_done',           'color', '#5a5d60'),
  ('tasks',   'color_step_locked',         'color', '#ececec')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 29
SELECT COUNT(*) AS `νέα_κλειδιά`
  FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('fuel_w_upload', 'fuel_w_upload_late', 'fuel_w_verify_wait', 'fuel_w_verify_now',
               'fuel_w_verify_late', 'fuel_w_done', 'strip_l1', 'strip_l2', 'strip_l3', 'strip_m2',
               'strip_m3', 'strip_unassigned', 'strip_who', 'strip_wait_assign', 'strip_b3', 'strip_d1',
               'strip_d2', 'strip_d3', 'strip_when', 'strip_tip_locked', 'strip_tip_monday',
               'strip_tip_done', 'strip_notes_h', 'strip_notes_none', 'strip_note_to',
               'strip_menu_assign', 'strip_menu_hist', 'color_step_done', 'color_step_locked');

-- Αναμενόμενο: clients 2 · common 2 · tasks 43
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
