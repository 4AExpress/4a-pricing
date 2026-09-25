-- 2026-09-25b_task_types_action.sql
-- Σύνδεσμος από την εργασία στο σημείο δράσης.
--
-- Η ΑΡΧΗ: το πού οδηγεί κάθε τύπος εργασίας ζει ΣΤΗ ΒΑΣΗ, όχι στον
-- κώδικα. Νέος τύπος εργασίας με δικό του προορισμό = δύο UPDATE, χωρίς
-- deploy. Κενό action_url = καμία αλλαγή στην οθόνη, κανένα κουμπί.
--
-- ΚΑΝΟΝΑΣ SERVER: MySQL 8.4.6 — ΟΧΙ `ADD COLUMN IF NOT EXISTS`, που
-- είναι MariaDB. Μόνο information_schema + PREPARE/EXECUTE.

-- ── 1. action_url ─────────────────────────────────────────────────────
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_types`
       ADD COLUMN `action_url` VARCHAR(200) NULL
       COMMENT ''σχετικό URL προορισμού· {client_id} {task_id} {offer_number}''',
    'SELECT ''η στήλη action_url υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_types'
    AND COLUMN_NAME  = 'action_url'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. action_label ───────────────────────────────────────────────────
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_types`
       ADD COLUMN `action_label` VARCHAR(60) NULL
       COMMENT ''κείμενο κουμπιού· κενό = χωρίς κουμπί''',
    'SELECT ''η στήλη action_label υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_types'
    AND COLUMN_NAME  = 'action_label'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. action_module ──────────────────────────────────────────────────
-- Ποιο module RBAC χρειάζεται ο χρήστης για να πατήσει το κουμπί.
-- ΔΕΝ εξάγεται από το όνομα αρχείου: σήμερα συμπίπτουν
-- (pricelist-clients.html -> pricelist-clients) αλλά αυτή ακριβώς η
-- σιωπηλή παραδοχή έχει ήδη κοστίσει τρία bugs σε αυτό το repo — βλ.
-- «καμία σιωπηλή προεπιλογή» στους κλειδωμένους κανόνες. Κενό = κανένας
-- έλεγχος, το κουμπί φαίνεται σε όλους.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_types`
       ADD COLUMN `action_module` VARCHAR(40) NULL
       COMMENT ''module RBAC του προορισμού· κενό = χωρίς έλεγχο''',
    'SELECT ''η στήλη action_module υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_types'
    AND COLUMN_NAME  = 'action_module'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 4. Γέμισμα — ΜΟΝΟ η open_code ─────────────────────────────────────
-- Οι άλλες έξι μένουν NULL: δεν ξέρουμε ακόμα πού οδηγούν, και ένας
-- λάθος προορισμός είναι χειρότερος από κανέναν.
UPDATE `4a_task_types`
   SET `action_url`    = 'pricelist-clients.html?client={client_id}&focus=account',
       `action_label`  = 'Άνοιγμα πελάτη',
       `action_module` = 'pricelist-clients'
 WHERE `code` = 'open_code';

-- ── 5. Επαλήθευση ─────────────────────────────────────────────────────
SELECT `code`, IFNULL(`action_label`, '—') AS lbl, IFNULL(`action_url`, '—') AS url
  FROM `4a_task_types`
 ORDER BY `sort_order`;
