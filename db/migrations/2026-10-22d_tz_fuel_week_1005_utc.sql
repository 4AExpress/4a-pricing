-- =====================================================================
--  2026-10-22d_tz_fuel_week_1005_utc.sql
--
--  ΖΩΝΗ ΩΡΑΣ (απόφαση 21/10, σύμβαση: στιγμές γεγονότων = UTC στη βάση).
--  Το migration 2026-10-07h έγραψε την αναδρομική εβδομάδα 05/10 με ώρα
--  «2026-10-07 07:31:00» ΟΠΩΣ δόθηκε, δηλαδή ώρα Αθήνας (EEST, UTC+3).
--  Η σύνδεση της βάσης είναι UTC (έλεγχος 21/10), άρα αποθηκεύτηκε 3 ώρες
--  μπροστά. Εδώ γίνεται 04:31:00 UTC, όπως κάθε άλλη στιγμή, ώστε η οθόνη
--  (UTC -> Αθήνα, time_lib.php) να δείχνει ξανά 07:31.
--
--  Αγγίζει ΜΟΝΟ ό,τι έγραψε το 07h, και ΜΟΝΟ αν έχει ακόμα το 07:31:
--    4a_tasks         1 γραμμή (fuel_weekly, fuel:2026-10-05): created_at, closed_at
--    4a_task_events   2 γραμμές της ίδιας εργασίας: created, done
--  Δεύτερη εκτέλεση: 0 γραμμές (idempotent). Κανένα DDL.
--
--  Αντίστροφο: rollback/2026-10-22d_tz_fuel_week_1005_utc.rollback.sql
--
--  MySQL 8.4. Η σύνδεση σε UTC (SYSTEM = UTC): η τιμή γράφεται αυτούσια.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

UPDATE `4a_task_events`
   SET `created_at` = '2026-10-07 04:31:00'
 WHERE `created_at` = '2026-10-07 07:31:00'
   AND `event` IN ('created', 'done')
   AND `task_id` IN (SELECT `id` FROM `4a_tasks`
                      WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05');

UPDATE `4a_tasks`
   SET `created_at` = '2026-10-07 04:31:00', `closed_at` = '2026-10-07 04:31:00'
 WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05'
   AND `created_at` = '2026-10-07 07:31:00' AND `closed_at` = '2026-10-07 07:31:00';

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 1 γραμμή, created_at = closed_at = 2026-10-07 04:31:00
SELECT `id`, `status`, `created_at`, `closed_at` FROM `4a_tasks`
 WHERE `task_code` = 'fuel_weekly' AND `dedupe_key` = 'fuel:2026-10-05';

-- Αναμενόμενο: created και done στις 2026-10-07 04:31:00
SELECT e.`id`, e.`event`, e.`created_at` FROM `4a_task_events` e
  JOIN `4a_tasks` t ON t.`id` = e.`task_id`
 WHERE t.`task_code` = 'fuel_weekly' AND t.`dedupe_key` = 'fuel:2026-10-05'
 ORDER BY e.`id`;
