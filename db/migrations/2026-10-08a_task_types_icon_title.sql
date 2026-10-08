-- =====================================================================
--  2026-10-08a_task_types_icon_title.sql
--
--  ΕΜΦΑΝΙΣΗ ΤΥΠΩΝ ΕΡΓΑΣΙΑΣ, ΣΤΗ ΒΑΣΗ.
--
--    icon            εικονιδιο του τυπου. NULL = χωρις εικονιδιο, οπως
--                    σημερα. Εμφανιζεται στην κεφαλιδα της ομαδας και στον
--                    τιτλο της εργασιας.
--    title_template  τιτλος με θεση για την εβδομαδα, π.χ.
--                    Γενικος επιναυλος {week} — αρχειο CMS
--                    Η οθονη βαζει στο {week} την εβδομαδα του payload
--                    (12–18/10). NULL = ο τιτλος ειναι το label.
--
--  Το label ΔΕΝ αλλαζει: το χρησιμοποιει και ο server σε μηνυματα
--  (κλειδωμενη — περιμενει: ...), οπου ενα {week} θα φαινοταν ωμο.
--
--  Μονο fuel_weekly και fuel_weekly_verify παιρνουν τιμες. Οι υπολοιποι
--  τυποι μενουν NULL. Καμια εργασια δεν αγγιζεται.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'icon') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `icon` VARCHAR(16) NULL
       COMMENT ''εικονιδιο τυπου, NULL = κανενα''
       AFTER `label`',
  'SELECT ''η στηλη icon υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'title_template') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `title_template` VARCHAR(160) NULL
       COMMENT ''τιτλος με {week} για την εβδομαδα του payload, NULL = το label''
       AFTER `icon`',
  'SELECT ''η στηλη title_template υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `4a_task_types`
   SET `icon` = '⛽', `title_template` = 'Γενικός επίναυλος {week} — αρχείο CMS'
 WHERE `code` = 'fuel_weekly';

UPDATE `4a_task_types`
   SET `icon` = '⛽', `title_template` = 'Έλεγχος γενικού επίναυλου {week} στο CMS'
 WHERE `code` = 'fuel_weekly_verify';

SELECT `code`, `label`, `icon`, `title_template`
  FROM `4a_task_types` ORDER BY `sort_order`, `code`;
