-- 4a_services.direction: κατεύθυνση υπηρεσίας για το CMS export. Idempotent.
-- IMPORT μόνο S1012, S1041, S1051, S1012_CY, S1012_GR· όλα τα υπόλοιπα EXPORT (default).
-- Η MySQL εδώ ΔΕΝ δέχεται ALTER TABLE ... ADD COLUMN IF NOT EXISTS,
-- οπότε ο έλεγχος γίνεται μέσω information_schema.

SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = '4a_services'
                AND COLUMN_NAME  = 'direction');
SET @sql := IF(@has = 0,
  'ALTER TABLE `4a_services` ADD COLUMN `direction` ENUM(''EXPORT'',''IMPORT'') NOT NULL DEFAULT ''EXPORT'' AFTER `country`',
  'SELECT ''direction: υπάρχει ήδη'' AS note');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `4a_services`
   SET `direction` = 'IMPORT'
 WHERE `code` IN ('S1012','S1041','S1051','S1012_CY','S1012_GR');

-- Πίνακας ελέγχου: ΟΛΕΣ οι υπηρεσίες, για να φανεί εισαγωγή που έμεινε EXPORT.
SELECT `id`, `code`, `name`, `type`, `country`, `direction`, `active`
  FROM `4a_services`
 ORDER BY `direction`, `code`;
