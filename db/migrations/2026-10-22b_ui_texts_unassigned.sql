-- =====================================================================
--  2026-10-22b_ui_texts_unassigned.sql
--
--  ΟΡΟΛΟΓΙΑ (απόφαση Απόστολου 20/10): «αδιάθετο / αδιάθετες / αδιάθετων»
--  και «Χωρίς ανάδοχο» (υπόμνημα) γίνονται «μη ανατεθειμένο / μη
--  ανατεθειμένες / μη ανατεθειμένων / Μη ανατεθειμένη» ΠΑΝΤΟΥ.
--
--  1. 2 κείμενα του 4a_ui_texts (scope tasks):
--     mine_empty_queue  (15b)  «→ Δείτε την Ουρά μη ανατεθειμένων ({n})»
--     strip_unassigned  (19b)  «μη ανατεθειμένο» (το βήμα ③ της κάρτας)
--     και 1 ΝΕΟ κλειδί (απόφαση 21/10):
--     strip_who_none          «Μη ανατεθειμένο · περιμένει ανάθεση»: γραμμή ΤΩΡΑ όταν
--                             δεν υπάρχει ανάδοχος, αντί για «Το κάνει: μη ανατεθειμένο»
--  2. Η ετικέτα της κατάστασης open στον 4a_task_statuses (25d), που
--     δείχνουν το badge και το υπόμνημα: «Μη ανατεθειμένη».
--
--  ΠΡΟΣΟΧΗ: γράφει ΠΑΝΩ από την τιμή της βάσης (και αν άλλαξε από την
--  οθόνη). Τις τρέχουσες τιμές τις επιβεβαιώνει η ανάγνωση πριν.
--  Ένα νέο κλειδί (strip_who_none), κανένα DDL. Τα κείμενα του κώδικα (καρτέλα «Ουρά»,
--  σύνοψη, κενή λίστα) αλλάζουν στο tasks.html.
--
--  Αντίστροφο: rollback/2026-10-22b_ui_texts_unassigned.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'mine_empty_queue',  'text',  '→ Δείτε την Ουρά μη ανατεθειμένων ({n})'),
  ('tasks',   'strip_unassigned',  'text',  'μη ανατεθειμένο'),
  ('tasks',   'strip_who_none',    'text',  'Μη ανατεθειμένο · περιμένει ανάθεση')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

UPDATE `4a_task_statuses` SET `label` = 'Μη ανατεθειμένη' WHERE `code` = 'open';

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 3 γραμμές με τα νέα κείμενα
SELECT `k`, `text` FROM `4a_ui_texts`
 WHERE `scope` = 'tasks' AND `k` IN ('mine_empty_queue', 'strip_unassigned', 'strip_who_none') ORDER BY `k`;

-- Αναμενόμενο: open = Μη ανατεθειμένη
SELECT `code`, `label` FROM `4a_task_statuses` WHERE `code` = 'open';

-- Αναμενόμενο: 0 (καμία παλιά λέξη σε κείμενο ή ετικέτα)
SELECT (SELECT COUNT(*) FROM `4a_ui_texts` WHERE `text` LIKE '%αδιάθετ%' OR `text` LIKE '%ανάδοχο%')
     + (SELECT COUNT(*) FROM `4a_task_statuses` WHERE `label` LIKE '%αδιάθετ%' OR `label` LIKE '%ανάδοχο%') AS `παλιά`;

-- Αναμενόμενο: clients 2 · common 3 · tasks 79 (78 + strip_who_none)
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
