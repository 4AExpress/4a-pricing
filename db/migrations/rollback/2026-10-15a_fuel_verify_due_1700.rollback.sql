-- =====================================================================
--  rollback/2026-10-15a_fuel_verify_due_1700.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-15a: κανόνας verify πίσω σε 0 / 12:00,
--  όρια χρώματος πίσω σε 12 / 3, η #88 πίσω σε 2026-10-19 12:00, και
--  σβήσιμο ΜΟΝΟ του γεγονότος του 15a. Ανεξάρτητο από τα rollback
--  των 14b και 14c.
--
--  Idempotent: κάθε UPDATE απαιτεί την τιμή που έγραψε το 15a.
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `4a_task_types` SET `due_time` = '12:00:00' WHERE `code` = 'fuel_weekly_verify' AND `due_offset_days` = 0 AND `due_time` = '17:00:00';

UPDATE `4a_task_types` SET `due_warn_hours` = 12 WHERE `code` = 'fuel_weekly_verify' AND `due_warn_hours` = 17 AND `due_urgent_hours` = 3;

UPDATE `4a_tasks` SET `due_at` = '2026-10-19 12:00:00' WHERE `id` = 88 AND `task_code` = 'fuel_weekly_verify' AND `due_at` = '2026-10-19 17:00:00';

DELETE FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-15a';

-- Αναμενόμενο: fuel_weekly -3 19:00:00 24 4 · fuel_weekly_verify 0 12:00:00 12 3
SELECT `code`, `due_offset_days`, `due_time`, `due_warn_hours`, `due_urgent_hours`
  FROM `4a_task_types` WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify') ORDER BY `sort_order`, `code`;

-- Αναμενόμενο: 88 fuel_weekly_verify 2026-10-19 12:00:00, και 0 γεγονότα.
SELECT `id`, `task_code`, `status`, `due_at`
  FROM `4a_tasks` WHERE `id` = 88;

SELECT COUNT(*) AS `γεγονότα_15a`
  FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-15a';
