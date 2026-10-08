-- =====================================================================
--  2026-10-07h_fuel_weekly_history.sql
--
--  ΙΣΤΟΡΙΚΟ: η εργασια fuel_weekly της εβδομαδας 2026-10-05, που εγινε
--  ΠΡΙΝ υπαρξει το συστημα. Καταχωριζεται αναδρομικα ως ΟΛΟΚΛΗΡΩΜΕΝΗ:
--
--    ολοκληρωσε ο χρηστης με id 1, 2026-10-07 07:31
--    EffectiveDate του αρχειου που ανεβηκε 01-Oct-2026
--    αποδειξη 69 / 69, επιπλεον 0, λειπουν 0
--
--  Τιμες επιναυλου ΔΕΝ γραφονται στο payload: το αρχειο εκεινης της
--  εβδομαδας δεν υπαρχει στο συστημα, και μια τιμη χωρις πηγη θα ηταν
--  εικασια.
--
--  Η ωρα γραφεται οπως δοθηκε, χωρις μετατροπη ζωνης (βλ. ανοιχτο θεμα
--  ζωνης ωρας στο HANDOFF).
--
--  ΔΕΝ δημιουργειται fuel_weekly_verify για αυτη την εβδομαδα.
--
--  Idempotent: INSERT IGNORE στο UNIQUE(task_code, dedupe_key), και τα
--  events μπαινουν μονο αν δεν υπαρχουν ηδη.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `4a_tasks`
  (`client_id`, `subject_key`, `country`, `task_code`, `offer_number`,
   `assigned_to`, `status`, `created_at`, `closed_at`, `closed_by`, `payload`)
VALUES
  (NULL, 'fuel:2026-10-05', 'BOTH', 'fuel_weekly', '',
   1, 'done', '2026-10-07 07:31:00', '2026-10-07 07:31:00', 1,
   JSON_OBJECT(
     'kind',           'fuel_weekly',
     'week_start',     '2026-10-05',
     'week_end',       '2026-10-11',
     'effective_date', '01-Oct-2026',
     'retroactive',    TRUE,
     'proof',          JSON_OBJECT('expected', 69, 'generated', 69, 'extra', 0, 'missing', 0)
   ));

INSERT INTO `4a_task_events` (`task_id`, `event`, `actor_id`, `note`, `created_at`)
SELECT t.`id`, 'created', NULL,
       'αναδρομική καταχώριση: η εργασία έγινε πριν υπάρξει το σύστημα',
       '2026-10-07 07:31:00'
  FROM `4a_tasks` t
 WHERE t.`task_code` = 'fuel_weekly' AND t.`dedupe_key` = 'fuel:2026-10-05'
   AND NOT EXISTS (SELECT 1 FROM `4a_task_events` e
                    WHERE e.`task_id` = t.`id` AND e.`event` = 'created');

INSERT INTO `4a_task_events` (`task_id`, `event`, `actor_id`, `note`, `created_at`)
SELECT t.`id`, 'done', 1,
       'αρχείο CMS ανέβηκε, ισχύς 01-Oct-2026, απόδειξη 69/69, επιπλέον 0, λείπουν 0',
       '2026-10-07 07:31:00'
  FROM `4a_tasks` t
 WHERE t.`task_code` = 'fuel_weekly' AND t.`dedupe_key` = 'fuel:2026-10-05'
   AND NOT EXISTS (SELECT 1 FROM `4a_task_events` e
                    WHERE e.`task_id` = t.`id` AND e.`event` = 'done');

SELECT t.`id`, t.`task_code`, t.`dedupe_key`, t.`status`, t.`assigned_to`, t.`closed_by`,
       t.`closed_at`, (SELECT COUNT(*) FROM `4a_task_events` e WHERE e.`task_id` = t.`id`) AS events
  FROM `4a_tasks` t
 WHERE t.`task_code` = 'fuel_weekly' AND t.`dedupe_key` = 'fuel:2026-10-05';
