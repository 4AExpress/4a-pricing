-- =====================================================================
--  rollback/2026-10-22d_tz_fuel_week_1005_utc.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-22d: η αναδρομική εβδομάδα 05/10 ξανά στο
--  «2026-10-07 07:31:00» του 07h (1 γραμμή 4a_tasks + 2 γεγονότα), ΜΟΝΟ
--  αν έχουν ακόμα το 04:31:00. Idempotent. Κανένα DDL.
--
--  MySQL 8.4.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `4a_task_events`
   SET `created_at` = '2026-10-07 07:31:00'
 WHERE `created_at` = '2026-10-07 04:31:00'
   AND `event` IN ('created', 'done')
   AND `task_id` IN (SELECT `id` FROM `4a_tasks`
                      WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05');

UPDATE `4a_tasks`
   SET `created_at` = '2026-10-07 07:31:00', `closed_at` = '2026-10-07 07:31:00'
 WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05'
   AND `created_at` = '2026-10-07 04:31:00' AND `closed_at` = '2026-10-07 04:31:00';

-- Αναμενόμενο: created_at = closed_at = 2026-10-07 07:31:00
SELECT `id`, `status`, `created_at`, `closed_at` FROM `4a_tasks`
 WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05';
