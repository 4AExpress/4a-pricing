-- =====================================================================
--  2026-10-07f_fuel_task_types.sql
--
--  ΤΥΠΟΙ ΕΡΓΑΣΙΑΣ ΓΙΑ ΤΟΝ ΕΒΔΟΜΑΔΙΑΙΟ ΓΕΝΙΚΟ ΕΠΙΝΑΥΛΟ. Φαση 3.
--
--  Δυο νεες στηλες στον 4a_task_types:
--
--    kind                   client | system. Οι τυποι client δημιουργουνται
--                           ανα πελατη απο το tasks_create.php. Οι τυποι
--                           system ΠΟΤΕ ανα πελατη, μονο απο το
--                           tasks_system.php. Χωρις αυτη τη στηλη, καθε νεος
--                           πελατης θα επαιρνε εργασια εβδομαδιαιου επιναυλου.
--                           Ολοι οι υπαρχοντες τυποι μενουν client (DEFAULT).
--
--    exclude_prev_assignee  1 = η εργασια ΔΕΝ πηγαινει σε οποιον εκανε την
--                           προηγουμενη της αλυσιδας (depends_on, ιδιο
--                           dedupe_key). Ο ελεγχος γινεται απο αλλον.
--                           Μονο το fuel_weekly_verify εχει 1.
--
--  Δυο νεοι τυποι, kind = system:
--    fuel_weekly         αρχειο γενικου επιναυλου της εβδομαδας στο CMS
--    fuel_weekly_verify  ελεγχος στο CMS απο αλλον, depends_on fuel_weekly
--
--  Ετικετες στη βαση. ready_check NULL. Χωρις action_url: το αρχειο
--  κατεβαινει απο την ιδια την καρτα της εργασιας.
--
--  ΣΕΙΡΑ: αυτο το migration ΠΡΙΝ ανεβει το tasks_create.php v1.1, που
--  διαβαζει τη στηλη kind.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'kind') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `kind` ENUM(''client'',''system'') NOT NULL DEFAULT ''client''
       COMMENT ''client = ανα πελατη (tasks_create), system = χωρις πελατη (tasks_system)''
       AFTER `active`',
  'SELECT ''η στηλη kind υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'exclude_prev_assignee') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `exclude_prev_assignee` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = οχι σε οποιον εκανε την προηγουμενη της αλυσιδας''
       AFTER `depends_on`',
  'SELECT ''η στηλη exclude_prev_assignee υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Η ζωνη ωρας του 4a_tasks.due_at, γραμμενη στη ιδια τη στηλη. Ιδιος
-- τυπος (DATETIME NULL), αλλαζει μονο το σχολιο. Ξανατρεχει χωρις ζημια.
ALTER TABLE `4a_tasks`
  MODIFY COLUMN `due_at` DATETIME NULL
    COMMENT 'προθεσμια, ΩΡΑ ΑΘΗΝΑΣ (Europe/Athens), οχι UTC. fuel_weekly: Παρασκευη 17:00 πριν το week_start';

INSERT INTO `4a_task_types`
  (`code`, `label`, `sort_order`, `active`, `kind`, `condition_key`,
   `depends_on`, `exclude_prev_assignee`, `sla_hours`, `ready_check`)
VALUES
  ('fuel_weekly',        'Γενικός επίναυλος εβδομάδας — αρχείο CMS', 900, 1, 'system', NULL,
   NULL,          0, NULL, NULL),
  ('fuel_weekly_verify', 'Έλεγχος γενικού επίναυλου στο CMS',        910, 1, 'system', NULL,
   'fuel_weekly', 1, NULL, NULL)
ON DUPLICATE KEY UPDATE
  `label`                 = VALUES(`label`),
  `sort_order`            = VALUES(`sort_order`),
  `active`                = VALUES(`active`),
  `kind`                  = VALUES(`kind`),
  `condition_key`         = VALUES(`condition_key`),
  `depends_on`            = VALUES(`depends_on`),
  `exclude_prev_assignee` = VALUES(`exclude_prev_assignee`),
  `sla_hours`             = VALUES(`sla_hours`),
  `ready_check`           = VALUES(`ready_check`);

SELECT `code`, `label`, `kind`, `depends_on`, `exclude_prev_assignee`, `ready_check`
  FROM `4a_task_types` ORDER BY `sort_order`, `code`;
