-- =====================================================================
--  2026-10-19b_ui_texts_fuel_week.sql
--
--  ΦΑΣΗ Α, Α2. Νέα κείμενα οθόνης στον 4a_ui_texts (scope tasks) για τις
--  ενδείξεις της εβδομάδας επίναυλου (ΤΙ · ΑΠΟ ΠΟΤΕ · ΕΩΣ ΠΟΤΕ) και τη
--  γραμμή του κλειδωμένου verify. Εγκεκριμένα 16/10 (PhaseA_plan §δ.2).
--
--  ΚΑΝΕΝΑΣ δεν τα διαβάζει πριν από την ενεργοποίηση (Α4): το
--  frontend του Α3 τα δείχνει ΜΟΝΟ όταν η ρίζα της εβδομάδας έχει βήμα.
--
--  Τιμές από τα δεδομένα, όχι από τον κώδικα:
--    {due}   το due_at της εργασίας, π.χ. Παρ 23/10 19:00
--    {from}  η Δευτέρα του week_start, π.χ. Δευ 26/10
--    {time}  η ώρα του due_at του verify, π.χ. 17:00
--    {name}  ο ανάδοχος της εργασίας ανεβάσματος
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
  ('tasks',   'fuel_w_upload',                'text',  '◷ ① Ανέβασμα στο CMS · τώρα · έως {due}'),
  ('tasks',   'fuel_w_upload_late',           'text',  '⚠ ① Ανέβασμα στο CMS · εκπρόθεσμο από {due}'),
  ('tasks',   'fuel_w_verify_wait',           'text',  '⏳ ② Επαλήθευση · από {from} · έως {time}'),
  ('tasks',   'fuel_w_verify_now',            'text',  '◷ ② Επαλήθευση · τώρα · έως {time}'),
  ('tasks',   'fuel_w_verify_late',           'text',  '⚠ ② Επαλήθευση · εκπρόθεσμη από {due}'),
  ('tasks',   'fuel_w_done',                  'text',  '✓ Σε ισχύ · ανέβηκε και επαληθεύτηκε'),
  ('tasks',   'fuel_verify_locked',           'text',  '🔒 Περιμένει το ανέβασμα ({name})'),
  ('tasks',   'fuel_verify_locked_none',      'text',  '🔒 Περιμένει το ανέβασμα (αδιάθετο)')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 8
SELECT COUNT(*) AS `νέα_κλειδιά`
  FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('fuel_w_upload', 'fuel_w_upload_late', 'fuel_w_verify_wait', 'fuel_w_verify_now',
               'fuel_w_verify_late', 'fuel_w_done', 'fuel_verify_locked', 'fuel_verify_locked_none');

-- Αναμενόμενο: clients 2 · common 2 · tasks 22
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
