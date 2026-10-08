-- =====================================================================
--  2026-10-08c_fuel_icon_due_thresholds.sql
--
--  ΕΜΦΑΝΙΣΗ ΚΑΡΤΑΣ ΕΠΙΝΑΥΛΟΥ, ΣΤΗ ΒΑΣΗ.
--
--  1. 4a_fuel_types.icon      εικονιδιο του τυπου, στην κεφαλιδα του
--                             πινακα τιμων («✈️ Αεροπορικος»). NULL =
--                             μονο η ετικετα. utf8mb4: το ✈️ ειναι δυο
--                             code points (U+2708 U+FE0F).
--
--  2. 4a_task_types.due_warn_hours / due_urgent_hours
--                             ορια χρωματος της προθεσμιας, σε ωρες:
--                               > warn            πρασινο
--                               warn .. urgent    πορτοκαλι
--                               < urgent          κοκκινο + «ΕΠΕΙΓΟΝ»
--                               μετα το due_at    κοκκινο που αναβοσβηνει
--                             NULL = καθολου χρωμα (ολοι οι τυποι πελατη).
--                             ΜΟΝΟ το fuel_weekly παιρνει 24 / 4.
--                             Το fuel_weekly_verify μενει NULL μεχρι τη
--                             Φαση 5 (due_rule): σημερα εχει την ιδια
--                             προθεσμια με το fuel_weekly (Παρ 17:00), που
--                             δεν ειναι δικη του, και θα εδειχνε ΕΠΕΙΓΟΝ
--                             μολις ξεκλειδωσει.
--
--  ΚΑΜΙΑ εργασια (4a_tasks) δεν αγγιζεται. Ουτε οι #76 / #77.
--
--  Τρεχει ΠΡΙΝ από το SCP των api/fuel_catalog.php και api/tasks_lib.php,
--  που διαβαζουν τις νεες στηλες.
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. 4a_fuel_types.icon ─────────────────────────────────────────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_fuel_types'
                   AND COLUMN_NAME  = 'icon') = 0,
  'ALTER TABLE `4a_fuel_types`
     ADD COLUMN `icon` VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL
       COMMENT ''εικονιδιο τυπου, NULL = κανενα''
       AFTER `label_en`',
  'SELECT ''η στηλη 4a_fuel_types.icon υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Τα εμοτζι ως UTF-8 bytes (X'...'), ωστε να μην εξαρτωνται από την
-- κωδικοποιηση του τερματικου που τρεχει το αρχειο:
--   ✈️ = E2 9C 88 EF B8 8F    🚚 = F0 9F 9A 9A
UPDATE `4a_fuel_types` SET `icon` = CONVERT(X'E29C88EFB88F' USING utf8mb4) WHERE `code` IN ('AIR', 'AIR_CY');
UPDATE `4a_fuel_types` SET `icon` = CONVERT(X'F09F9A9A'     USING utf8mb4) WHERE `code` = 'ROAD';

-- ── 2. 4a_task_types.due_warn_hours / due_urgent_hours ────────────────
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_warn_hours') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `due_warn_hours` INT NULL
       COMMENT ''προθεσμια: πανω από τοσες ωρες πρασινο, NULL = χωρις χρωμα''',
  'SELECT ''η στηλη due_warn_hours υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_types'
                   AND COLUMN_NAME  = 'due_urgent_hours') = 0,
  'ALTER TABLE `4a_task_types`
     ADD COLUMN `due_urgent_hours` INT NULL
       COMMENT ''προθεσμια: κατω από τοσες ωρες κοκκινο + ΕΠΕΙΓΟΝ, NULL = χωρις χρωμα''
       AFTER `due_warn_hours`',
  'SELECT ''η στηλη due_urgent_hours υπαρχει ηδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE `4a_task_types`
   SET `due_warn_hours` = 24, `due_urgent_hours` = 4
 WHERE `code` = 'fuel_weekly';

UPDATE `4a_task_types`
   SET `due_warn_hours` = NULL, `due_urgent_hours` = NULL
 WHERE `code` = 'fuel_weekly_verify';

-- ── 3. Επαληθευση ─────────────────────────────────────────────────────
SELECT `code`, `label_el`, `icon`, HEX(`icon`) AS icon_hex, `sort_order`
  FROM `4a_fuel_types` ORDER BY `sort_order`, `code`;

SELECT `code`, `due_warn_hours`, `due_urgent_hours`
  FROM `4a_task_types` ORDER BY `sort_order`, `code`;
