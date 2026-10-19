-- =====================================================================
--  rollback/2026-10-23b_fuel_verify_values_perm.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-23b: σβήνει το module fuel_verify_values και τα
--  δικαιώματά του. Χωρίς αυτό ΚΑΝΕΙΣ (ούτε ο administrator) δεν βλέπει τις
--  αναμενόμενες τιμές: τρέχει ΜΑΖΙ με το rollback του 2026-10-23a, ή αφού
--  ο επαληθευτής απενεργοποιηθεί (from_week).
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- user_permissions: ο πίνακας μπορεί να λείπει (auth.php τον διαβάζει σε try).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_permissions') = 1,
  'DELETE FROM `user_permissions` WHERE `module_id` = ''fuel_verify_values''',
  'SELECT ''χωρίς πίνακα user_permissions'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

DELETE FROM `role_permissions` WHERE `module_id` = 'fuel_verify_values';
DELETE FROM `modules`          WHERE `id`        = 'fuel_verify_values';

-- Αναμενόμενο: 0 · 0
SELECT (SELECT COUNT(*) FROM `modules`          WHERE `id` = 'fuel_verify_values')        AS `modules`,
       (SELECT COUNT(*) FROM `role_permissions` WHERE `module_id` = 'fuel_verify_values') AS `role_permissions`;
