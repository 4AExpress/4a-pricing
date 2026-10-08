-- =====================================================================
--  2026-10-07g_fuel_task_skills.sql
--
--  ΔΕΞΙΟΤΗΤΕΣ για τις εργασιες fuel_weekly και fuel_weekly_verify.
--  Χρηστες με id 1 και 2, country BOTH, και για τους δυο τυπους.
--  Ονοματα δεν γραφονται εδω: η ταυτοτητα ειναι το user_id.
--
--  Η εργασια BOTH θελει δεξιοτητα BOTH (αυστηρη ερμηνεια, tasks_lib.php).
--  Το verify δεν πηγαινει σε οποιον εκανε το fuel_weekly της ιδιας
--  εβδομαδας (exclude_prev_assignee), αρα με δυο χρηστες ο ενας κανει το
--  αρχειο και ο αλλος τον ελεγχο.
--
--  granted_by NULL = μεταπτωση. INSERT IGNORE στο
--  UNIQUE(user_id, task_code, country): idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `4a_user_task_skills` (`user_id`, `task_code`, `country`, `granted_by`)
VALUES
  (1, 'fuel_weekly',        'BOTH', NULL),
  (1, 'fuel_weekly_verify', 'BOTH', NULL),
  (2, 'fuel_weekly',        'BOTH', NULL),
  (2, 'fuel_weekly_verify', 'BOTH', NULL);

SELECT `user_id`, `task_code`, `country`
  FROM `4a_user_task_skills`
 WHERE `task_code` IN ('fuel_weekly', 'fuel_weekly_verify')
 ORDER BY `task_code`, `user_id`;
