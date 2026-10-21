-- =====================================================================
--  rollback/2026-10-22b_ui_texts_unassigned.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-22b: επαναφέρει τα 2 κείμενα στις τιμές των
--  15b / 19b, την ετικέτα της open στην τιμή του 25d, και σβήνει το ΝΕΟ
--  κλειδί strip_who_none. Το frontend πριν από το 22b δεν το διαβάζει·
--  το frontend ΜΕ το 22b θέλει πρώτα επαναφορά του tasks.html.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('tasks',   'mine_empty_queue',  'text',  '→ Δείτε την Ουρά αδιάθετων ({n})'),
  ('tasks',   'strip_unassigned',  'text',  'αδιάθετο')
ON DUPLICATE KEY UPDATE `text` = VALUES(`text`);

UPDATE `4a_task_statuses` SET `label` = 'Χωρίς ανάδοχο' WHERE `code` = 'open';

DELETE FROM `4a_ui_texts` WHERE `scope` = 'tasks' AND `k` = 'strip_who_none';

-- Αναμενόμενο: 2 γραμμές με τα παλιά κείμενα (καμία strip_who_none) · open = Χωρίς ανάδοχο
SELECT `k`, `text` FROM `4a_ui_texts`
 WHERE `scope` = 'tasks' AND `k` IN ('mine_empty_queue', 'strip_unassigned', 'strip_who_none') ORDER BY `k`;
SELECT `code`, `label` FROM `4a_task_statuses` WHERE `code` = 'open';
