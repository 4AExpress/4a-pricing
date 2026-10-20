-- =====================================================================
--  2026-10-22a_fuel_weekly_activate.sql
--
--  Α4 (e-α): ΕΝΕΡΓΟΠΟΙΗΣΗ της κάρτας επίναυλου για το fuel_weekly.
--  Ο διακόπτης είναι οι γραμμές του 4a_task_steps για τον τύπο (2026-10-12b).
--
--  1. 4a_task_steps, task_code = fuel_weekly:
--     kind step   download (10) · upload (20) · uploaded (30, τελικό)
--     kind event  upload_failed (κόκκινο) · admin_override (κόκκινο) ·
--                 override_acknowledged · done (παλιά ροή)
--     kind ui     29 κείμενα της κάρτας = STEP_UI_KEYS του frontend/tasks.html
--     Κείμενα: K4a_review_1014.md (εγκρίθηκαν 14/10). Τρία tooltip ΝΕΑ, τελικά
--     κείμενα του Απόστολου 20/10 (αγγλικός όρος — τι κάνει το κουμπί):
--     tip_redownload, tip_upload_wait, tip_upload_done.
--     menu_view / tip_view (14/10) ΔΕΝ μπαίνουν: ο κώδικας δεν τα διαβάζει πια.
--  2. 4a_task_types: allow_na = allow_release = allow_reject = allow_steal = 0
--     για fuel_weekly ΚΑΙ fuel_weekly_verify (απόφαση 16/10).
--
--  Το fuel_weekly_verify ΔΕΝ παίρνει βήματα: το ③ μένει γενικό done.
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αλλάζει. Οι ήδη ανοιχτές (#109) δείχνουν
--  την κάρτα από το τρέχον βήμα, που βγαίνει από τα γεγονότα.
--
--  Αντίστροφο: rollback/2026-10-22a_fuel_weekly_activate.rollback.sql
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_task_steps` (`task_code`, `kind`, `step_code`, `sort_order`, `action`, `done_event`, `is_final`,
                             `status_label`, `hint`, `button_label`, `button_icon`, `timeline_label`, `alert`) VALUES
  ('fuel_weekly', 'step',  'download',              10, 'download', 'downloaded', 0,
   'Αναμονή λήψης αρχείου', 'Κατέβασε το αρχείο της εβδομάδας για το CMS.', 'Λήψη αρχείου CMS', '⬇', 'Λήψη αρχείου', 0),
  ('fuel_weekly', 'step',  'upload',                20, 'upload',   'uploaded',   0,
   'Αναμονή ανεβάσματος', 'Ανέβασε το αρχείο στο CMS (Import → Fuel Charge Import) και μετά επιβεβαίωσε με τα 4 νούμερα του Merge Result.',
   'Επιβεβαίωση ανεβάσματος', '⬆', 'Ανέβηκε στο CMS', 0),
  ('fuel_weekly', 'step',  'uploaded',              30, NULL,       NULL,         1,
   'Ανέβηκε · ισχύει από Δευ {week_start}', 'Τίποτα άλλο σήμερα. Η επαλήθευση τιμών γίνεται Δευ {week_start}.', NULL, NULL, NULL, 0),
  ('fuel_weekly', 'event', 'upload_failed',          0, NULL,       NULL,         0,
   NULL, NULL, NULL, NULL, '⚠ Επιβεβαίωση απορρίφθηκε · τα νούμερα δεν ταιριάζουν', 1),
  ('fuel_weekly', 'event', 'admin_override',         0, NULL,       NULL,         0,
   NULL, NULL, NULL, NULL, 'Παράκαμψη από διαχειριστή', 1),
  ('fuel_weekly', 'event', 'override_acknowledged',  0, NULL,       NULL,         0,
   NULL, NULL, NULL, NULL, 'Η παράκαμψη επιβεβαιώθηκε («Το είδα»)', 0),
  ('fuel_weekly', 'event', 'done',                   0, NULL,       NULL,         0,
   NULL, NULL, NULL, NULL, 'Ολοκληρώθηκε (παλιά ροή)', 0)
ON DUPLICATE KEY UPDATE `step_code` = `step_code`;

INSERT INTO `4a_task_steps` (`task_code`, `kind`, `step_code`, `text`) VALUES
  ('fuel_weekly', 'ui', 'h_do',            'ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ'),
  ('fuel_weekly', 'ui', 'h_status',        'ΚΑΤΑΣΤΑΣΗ'),
  ('fuel_weekly', 'ui', 'who',             'Το κάνει: {name}'),
  ('fuel_weekly', 'ui', 'menu_override',   'Παράκαμψη (admin)…'),
  ('fuel_weekly', 'ui', 'tip_override',    'Override — Κλείνει την εργασία χωρίς τα βήματά της, με υποχρεωτικό λόγο. Καταγράφεται και οι άλλοι διαχειριστές βλέπουν κόκκινο σήμα'),
  ('fuel_weekly', 'ui', 'tip_redownload',  'Re-download — Κατεβάζει ξανά το ίδιο αρχείο. Καταγράφεται στο ιστορικό· το βήμα μένει «έγινε».'),
  ('fuel_weekly', 'ui', 'tip_upload_wait', 'Confirm upload — Η πλατφόρμα δεν ανεβάζει το αρχείο. Πάτα το αφού το ανεβάσεις εσύ στο CMS.'),
  ('fuel_weekly', 'ui', 'tip_upload_done', 'Uploaded — Δηλώθηκε ότι ανέβηκε στο CMS. Δεν χρειάζεται να ξαναγίνει.'),
  ('fuel_weekly', 'ui', 'tip_more',        'More — Υπόλοιπες ενέργειες'),
  ('fuel_weekly', 'ui', 'ov_box',          'Παράκαμψη από {name} · {at}'),
  ('fuel_weekly', 'ui', 'ov_ack',          'Το είδα'),
  ('fuel_weekly', 'ui', 'tip_ack',         'Acknowledge — Σβήνει το κόκκινο σήμα για όλους τους διαχειριστές. Καταγράφεται'),
  ('fuel_weekly', 'ui', 'tl_late',         '(εκ των υστέρων)'),
  ('fuel_weekly', 'ui', 'tip_late',        'Late entry — Καταχωρήθηκε αργότερα, η ώρα είναι η πραγματική'),
  ('fuel_weekly', 'ui', 'up_title',        'Επιβεβαίωση ανεβάσματος'),
  ('fuel_weekly', 'ui', 'up_hint',         'Από το Merge Result του CMS (Import → Fuel Charge Import). Το αρχείο έχει {rows} γραμμές.'),
  ('fuel_weekly', 'ui', 'up_send',         'Επιβεβαίωση'),
  ('fuel_weekly', 'ui', 'up_err_fill',     'Συμπλήρωσε και τα 4 νούμερα (ακέραιοι ≥ 0)'),
  ('fuel_weekly', 'ui', 'up_err_total',    'Total {n} ≠ {rows} γραμμές του αρχείου'),
  ('fuel_weekly', 'ui', 'up_err_exc',      'Exception {n} ≠ 0'),
  ('fuel_weekly', 'ui', 'up_warn_prefix',  'Προσοχή:'),
  ('fuel_weekly', 'ui', 'up_warn_sum',     'Merge + Updated ≠ Total'),
  ('fuel_weekly', 'ui', 'up_warn_upd',     'Updated > 0: ενημερώθηκαν τιμές που υπήρχαν ήδη'),
  ('fuel_weekly', 'ui', 'ov_title',        'Παράκαμψη από διαχειριστή'),
  ('fuel_weekly', 'ui', 'ov_warn',         'Η εργασία κλείνει χωρίς τα βήματά της. Καταγράφεται με κόκκινη σήμανση και οι άλλοι διαχειριστές βλέπουν κόκκινο σήμα μέχρι να πατήσουν «Το είδα».'),
  ('fuel_weekly', 'ui', 'ov_reason',       'Λόγος (υποχρεωτικός, τουλάχιστον 10 χαρακτήρες)'),
  ('fuel_weekly', 'ui', 'ov_short',        'Ο λόγος θέλει τουλάχιστον 10 χαρακτήρες.'),
  ('fuel_weekly', 'ui', 'ov_send',         'Παράκαμψη'),
  ('fuel_weekly', 'ui', 'already_closed',  'Η εργασία είναι ήδη ολοκληρωμένη')
ON DUPLICATE KEY UPDATE `step_code` = `step_code`;

UPDATE `4a_task_types`
   SET `allow_na` = 0, `allow_release` = 0, `allow_reject` = 0, `allow_steal` = 0
 WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify');

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: step 3 · event 4 · ui 29
SELECT `kind`, COUNT(*) AS `γραμμές`
  FROM `4a_task_steps` WHERE `task_code` = 'fuel_weekly' GROUP BY `kind` ORDER BY `kind`;

-- Αναμενόμενο: 36 (κανένας άλλος τύπος)
SELECT COUNT(*) AS `σύνολο_βημάτων` FROM `4a_task_steps`;

-- Αναμενόμενο: fuel_weekly 0 0 0 0 0 · fuel_weekly_verify 0 0 0 0 1
SELECT `code`, `allow_na`, `allow_release`, `allow_reject`, `allow_steal`, `close_from_week_start`
  FROM `4a_task_types` WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify') ORDER BY `code`;
