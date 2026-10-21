-- =====================================================================
--  2026-10-23c_ui_texts_verifier.sql
--
--  ΕΠΑΛΗΘΕΥΤΗΣ: κείμενα οθόνης και χρώματα στον 4a_ui_texts (scope tasks),
--  εγκεκριμένα 19/10 (4a-private/verifier_texts.md):
--    vf_*        παράθυρο, αποτέλεσμα, λωρίδα ③, επαναφορά, παράκαμψη,
--                ιστορικό, μηνύματα σφάλματος (vf_err_<κωδικός vf του server>)
--    color_vf_*  λάθος (ίδιο με το «εκπρόθεσμο», #cc0000), σωστή, επιλογή.
--                kind = color, ΜΟΝΟ #rrggbb.
--
--  vf_line_values προστίθεται στο τέλος των vf_alert_line / vf_hist_line
--  ΜΟΝΟ όταν ο server στείλει picked / expected, δηλαδή ΜΟΝΟ με το
--  δικαίωμα fuel_verify_values.
--
--  ΚΑΝΕΝΑΣ δεν τα διαβάζει πριν από την ενεργοποίηση του επαληθευτή
--  (2026-10-23a + from_week): η οθόνη τα δείχνει ΜΟΝΟ όταν το verify έχει
--  `verifier` από τον server.
--
--  Τιμές από τα δεδομένα: {week} 26/10–01/11 · {date} 26/10 · {value},
--  {picked}, {expected} π.χ. 49.75 · {n} / {total} πλήθη · {at} / {name}
--  πότε / ποιος · {client} {station} {zone} {service} {type} η γραμμή ·
--  {mark} ✓ / ✗.
--
--  Προϋπόθεση: ο πίνακας του 2026-10-15b. Idempotent: κείμενο που υπάρχει
--  ήδη ΔΕΝ ξαναγράφεται.
--
--  Αντίστροφο: rollback/2026-10-23c_ui_texts_verifier.rollback.sql
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'vf_title',            'text',  '🔍 Επαλήθευση επίναυλου {week} στο CMS'),
  ('tasks',   'vf_hint',             'text',  'Στο Price Calculator του CMS έλεγξε κάθε γραμμή με ημερομηνία από {date} και μετά, και διάλεξε το ποσοστό επίναυλου που δείχνει.'),
  ('tasks',   'vf_col_client',       'text',  'Πελάτης'),
  ('tasks',   'vf_col_zone',         'text',  'Ζώνη'),
  ('tasks',   'vf_col_service',      'text',  'Υπηρεσία'),
  ('tasks',   'vf_col_type',         'text',  'Τύπος καυσίμου'),
  ('tasks',   'vf_col_pick',         'text',  'Τιμή στο CMS'),
  ('tasks',   'vf_zone',             'text',  '{station}-{zone}'),
  ('tasks',   'vf_option',           'text',  '{value}%'),
  ('tasks',   'vf_expected',         'text',  'αναμενόμενη: {value}%'),
  ('tasks',   'vf_send',             'text',  'Υποβολή'),
  ('tasks',   'vf_cancel',           'text',  'Ακύρωση'),
  ('tasks',   'vf_tip_send',         'text',  'Submit — Ελέγχει όλες τις γραμμές μαζί. Μία προσπάθεια: αν κάτι είναι λάθος, ο επαληθευτής κλειδώνει'),
  ('tasks',   'vf_gate',             'text',  'Η υποβολή ανοίγει Δευ {date} 00:00'),
  ('tasks',   'vf_line_ok',          'text',  '✓ σωστή'),
  ('tasks',   'vf_line_wrong',       'text',  '✗ δεν συμφωνεί'),
  ('tasks',   'vf_done',             'text',  '✓ Επαλήθευση επίναυλου {week} στο CMS ολοκληρώθηκε · {total}/{total} σωστές'),
  ('tasks',   'vf_wrong',            'text',  '✗ {n} από {total} γραμμές δεν συμφωνούν με το CMS. Ο επαληθευτής κλείδωσε και περιμένει διαχειριστή.'),
  ('tasks',   'vf_locked',           'text',  '🔒 Κλειδωμένος μετά από λάθος · {at} · {name} · περιμένει επαναφορά από διαχειριστή'),
  ('tasks',   'vf_strip_locked',     'text',  '🔒 Κλειδωμένο · περιμένει διαχειριστή'),
  ('tasks',   'vf_d3_ok',            'text',  'Επαληθεύτηκε {n}/{total} · {at} · {name}'),
  ('tasks',   'vf_alert',            'text',  '✗ Λάθος επαλήθευση {n}/{total} · {at} · {name}'),
  ('tasks',   'vf_alert_line',       'text',  '{client} · {station}-{zone} · {service} · {type}'),
  ('tasks',   'vf_line_values',      'text',  ' · επιλέχθηκε {picked}% · αναμενόμενη {expected}%'),
  ('tasks',   'vf_tip_dl_locked',    'text',  'Download — Δεν έχεις δικαίωμα λήψης αυτού του αρχείου'),
  ('tasks',   'vf_reset_btn',        'text',  '↺ Επαναφορά επαληθευτή…'),
  ('tasks',   'vf_tip_reset',        'text',  'Reset — Ξεκλειδώνει με νέες επιλογές. Πρώτα έλεγξε ή ξανανέβασε στο CMS'),
  ('tasks',   'vf_reset_title',      'text',  'Επαναφορά επαληθευτή'),
  ('tasks',   'vf_reset_hint',       'text',  'Ο επαληθευτής ξεκινά με νέες επιλογές για όλες τις γραμμές. Γράψε τι ελέγχθηκε ή διορθώθηκε στο CMS.'),
  ('tasks',   'vf_reason',           'text',  'Λόγος (υποχρεωτικός)'),
  ('tasks',   'vf_reset_send',       'text',  'Επαναφορά'),
  ('tasks',   'vf_tip_reset_self',   'text',  'Την επαναφορά την κάνει άλλος διαχειριστής από αυτόν που απάντησε'),
  ('tasks',   'vf_ov_btn',           'text',  'Παράκαμψη (admin)…'),
  ('tasks',   'vf_ov_title',         'text',  'Παράκαμψη επαλήθευσης'),
  ('tasks',   'vf_ov_warn',          'text',  'Η επαλήθευση κλείνει χωρίς τον έλεγχο στο CMS.'),
  ('tasks',   'vf_ov_send',          'text',  'Παράκαμψη'),
  ('tasks',   'vf_ov_box',           'text',  'Παράκαμψη από {name} · {at}'),
  ('tasks',   'vf_ov_ack',           'text',  'Το είδα'),
  ('tasks',   'vf_tip_ack',          'text',  'Acknowledge — Σβήνει το κόκκινο σήμα'),
  ('tasks',   'vf_ev_ok',            'text',  'επαλήθευση: όλες σωστές'),
  ('tasks',   'vf_ev_wrong',         'text',  'επαλήθευση: λάθος'),
  ('tasks',   'vf_ev_reset',         'text',  'επαναφορά επαληθευτή'),
  ('tasks',   'vf_ev_override',      'text',  'παράκαμψη από διαχειριστή'),
  ('tasks',   'vf_hist_line',        'text',  '{mark} {client} · {station}-{zone} · {service} · {type}'),
  ('tasks',   'vf_err_off',          'text',  'Ο επαληθευτής δεν είναι ενεργός για αυτή την εβδομάδα'),
  ('tasks',   'vf_err_config',       'text',  'Οι ρυθμίσεις του επαληθευτή δεν είναι έγκυρες · ενημέρωσε διαχειριστή'),
  ('tasks',   'vf_err_no_prices',    'text',  'Δεν υπάρχουν τιμές εβδομάδας για τον έλεγχο'),
  ('tasks',   'vf_err_draw',         'text',  'Δεν βγήκαν διαφορετικές επιλογές · ενημέρωσε διαχειριστή'),
  ('tasks',   'vf_err_forbidden',    'text',  'Δεν έχεις δικαίωμα να ανοίξεις τον επαληθευτή'),
  ('tasks',   'vf_err_owner',        'text',  'Την επαλήθευση την υποβάλλει μόνο ο ανάδοχος'),
  ('tasks',   'vf_err_locked',       'text',  'Ο επαληθευτής είναι κλειδωμένος · περιμένει επαναφορά από διαχειριστή'),
  ('tasks',   'vf_err_reload',       'text',  'Οι επιλογές άλλαξαν · άνοιξε ξανά τον επαληθευτή'),
  ('tasks',   'vf_err_incomplete',   'text',  'Διάλεξε τιμή σε όλες τις γραμμές'),
  ('tasks',   'vf_err_bad_value',    'text',  'Η τιμή δεν είναι από τις επιλογές της γραμμής · άνοιξε ξανά τον επαληθευτή'),
  ('tasks',   'vf_err_not_locked',   'text',  'Ο επαληθευτής δεν είναι κλειδωμένος'),
  ('tasks',   'vf_err_reset_self',   'text',  'Την επαναφορά την κάνει άλλος διαχειριστής'),
  ('tasks',   'vf_err_reason',       'text',  'Γράψε λόγο, τουλάχιστον {n} χαρακτήρες'),
  ('tasks',   'vf_err_admin',        'text',  'Μόνο διαχειριστής μπορεί να το κάνει'),
  ('tasks',   'vf_err_use_verifier', 'text',  'Η επαλήθευση κλείνει μόνο από το «🔍 Επαλήθευση»'),
  ('tasks',   'vf_err_no_download',  'text',  'Δεν έχεις δικαίωμα λήψης αυτού του αρχείου'),
  ('tasks',   'color_vf_wrong',      'color', '#cc0000'),
  ('tasks',   'color_vf_ok',         'color', '#2e7d32'),
  ('tasks',   'color_vf_pick',       'color', '#1565c0')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 63
SELECT COUNT(*) AS `νέα_κλειδιά`
  FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('vf_title', 'vf_hint', 'vf_col_client', 'vf_col_zone', 'vf_col_service', 'vf_col_type',
               'vf_col_pick', 'vf_zone', 'vf_option', 'vf_expected', 'vf_send', 'vf_cancel',
               'vf_tip_send', 'vf_gate', 'vf_line_ok', 'vf_line_wrong', 'vf_done', 'vf_wrong',
               'vf_locked', 'vf_strip_locked', 'vf_d3_ok', 'vf_alert', 'vf_alert_line', 'vf_line_values',
               'vf_tip_dl_locked', 'vf_reset_btn', 'vf_tip_reset', 'vf_reset_title', 'vf_reset_hint',
               'vf_reason', 'vf_reset_send', 'vf_tip_reset_self', 'vf_ov_btn', 'vf_ov_title',
               'vf_ov_warn', 'vf_ov_send', 'vf_ov_box', 'vf_ov_ack', 'vf_tip_ack', 'vf_ev_ok',
               'vf_ev_wrong', 'vf_ev_reset', 'vf_ev_override', 'vf_hist_line', 'vf_err_off',
               'vf_err_config', 'vf_err_no_prices', 'vf_err_draw', 'vf_err_forbidden', 'vf_err_owner',
               'vf_err_locked', 'vf_err_reload', 'vf_err_incomplete', 'vf_err_bad_value',
               'vf_err_not_locked', 'vf_err_reset_self', 'vf_err_reason', 'vf_err_admin',
               'vf_err_use_verifier', 'vf_err_no_download', 'color_vf_wrong', 'color_vf_ok',
               'color_vf_pick');

-- Αναμενόμενο (μετά τα 15b, 19b, 21a, 22b και 22c): clients 2 · common 3 · tasks 142 (79 + 63)
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
