-- 2026-09-25_clients_is_demo.sql
-- Λειτουργία επίδειξης — docs/tasks_demo_mode_spec.md
--
-- Μία στήλη στον 4a_clients. Οι διαχειριστές αποκτούν πλήρη ελευθερία
-- ΜΟΝΟ στις εργασίες πελατών με is_demo = 1· για πραγματικούς πελάτες
-- κανένας κανόνας δεν αλλάζει.
--
-- ΚΑΝΟΝΑΣ SERVER: MySQL 8.4.6, ΟΧΙ MariaDB. Το `ALTER TABLE ... ADD
-- COLUMN IF NOT EXISTS` ΔΕΝ υπάρχει — σκάει με syntax error. Ο μόνος
-- ιδεμποτεντικός τρόπος είναι information_schema + PREPARE/EXECUTE.

-- ── 1. Η στήλη ────────────────────────────────────────────────────────
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_clients`
       ADD COLUMN `is_demo` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''1 = πελάτης επίδειξης/δοκιμής, εκτός στατιστικών''',
    'SELECT ''η στήλη is_demo υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_clients'
    AND COLUMN_NAME  = 'is_demo'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Ευρετήριο ──────────────────────────────────────────────────────
-- Κάθε ερώτημα συνάθροισης θα φιλτράρει is_demo = 0 (βλ. spec §2γ), και
-- το badge το κάνει σε κάθε φόρτωση σελίδας. Με 48 πελάτες δεν μετράει
-- σήμερα· μπαίνει τώρα γιατί μετά θα ξεχαστεί.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_clients` ADD INDEX `ix_is_demo` (`is_demo`)',
    'SELECT ''το ευρετήριο ix_is_demo υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_clients'
    AND INDEX_NAME   = 'ix_is_demo'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Αρχείο αλλαγών της σημαίας ─────────────────────────────────────
-- Ίδιο σχήμα με τον 4a_cod_config_log: παλιά τιμή, νέα τιμή, ποιος, πότε.
-- Γράφεται ΜΟΝΟ όταν η τιμή πράγματι αλλάζει — ένα save που την αφήνει
-- ως έχει δεν παράγει γραμμή, αλλιώς το αρχείο γεμίζει με μη-γεγονότα.
--
-- Γιατί υπάρχει: ένα λάθος UPDATE που κάνει ΠΡΑΓΜΑΤΙΚΟ πελάτη
-- «δοκιμαστικό» τον εξαφανίζει από τη σύνοψη, το κόκκινο σήμα και κάθε
-- στατιστικό, ΧΩΡΙΣ κανένα μήνυμα λάθους. Χωρίς αρχείο, τέτοιο σφάλμα
-- δεν ανιχνεύεται ποτέ — ούτε καν αναδρομικά.
CREATE TABLE IF NOT EXISTS `4a_client_flag_log` (
    `id`         BIGINT       AUTO_INCREMENT PRIMARY KEY,
    `client_id`  BIGINT       NOT NULL,
    `flag`       VARCHAR(32)  NOT NULL,
    `old_value`  TINYINT(1)       NULL,
    `new_value`  TINYINT(1)   NOT NULL,
    `changed_by` INT              NULL,
    `changed_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX `ix_client` (`client_id`),
    INDEX `ix_changed_at` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ΧΩΡΙΣ FOREIGN KEY: κανένας πίνακας 4a_* δεν έχει, και το αρχείο πρέπει
-- να επιβιώνει ακόμα κι αν ο πελάτης σβηστεί με το χέρι κάποτε.

-- ── 4. Επαλήθευση ─────────────────────────────────────────────────────
-- ΟΛΟΙ οι υπάρχοντες πελάτες μένουν πραγματικοί. Καμία αναδρομική
-- σήμανση: οι παλιοί δοκιμαστικοί καθαρίστηκαν στις 24/09 και δεν
-- υπάρχουν. Το αναμενόμενο είναι demo = 0 σε κάθε γραμμή.
SELECT
  COUNT(*)                                   AS clients_total,
  SUM(CASE WHEN `is_demo` = 1 THEN 1 ELSE 0 END) AS demo_clients,
  SUM(CASE WHEN `is_demo` = 0 THEN 1 ELSE 0 END) AS real_clients
FROM `4a_clients`;
