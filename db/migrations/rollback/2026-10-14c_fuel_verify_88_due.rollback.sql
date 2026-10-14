-- =====================================================================
--  rollback/2026-10-14c_fuel_verify_88_due.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-14c: η #88 πίσω στην Παρασκευή
--  2026-10-16 17:00 και σβήσιμο ΜΟΝΟ του γεγονότος του 14c.
--  Ανεξάρτητο από το rollback του 14b.
--
--  Idempotent: το UPDATE απαιτεί την τιμή που έγραψε το 14c.
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `4a_tasks` SET `due_at` = '2026-10-16 17:00:00' WHERE `id` = 88 AND `task_code` = 'fuel_weekly_verify' AND `due_at` = '2026-10-19 12:00:00';

DELETE FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-14c';

-- Αναμενόμενο: 88 fuel_weekly_verify 2026-10-16 17:00:00, και 0 γεγονότα.
SELECT `id`, `task_code`, `status`, `due_at`
  FROM `4a_tasks` WHERE `id` = 88;

SELECT COUNT(*) AS `γεγονότα_14c`
  FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-14c';
