-- =====================================================================
--  4a-pricing · Σύστημα εργασιών — Φάση 1: θεμέλια
--  Αρχείο: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Όταν ένας πελάτης αποδεχτεί προσφορά, ακολουθεί χειρωνακτική
--  αλυσίδα: άνοιγμα κωδικού, καταχώρηση τιμών στο CMS, COD, επίναυλος,
--  ενημέρωση πελάτη, έντυπο αντικαταβολών, εκμάθηση. Σήμερα δεν
--  καταγράφεται πουθενά ποιος ανέλαβε τι και τι έμεινε πίσω: ο έλεγχος
--  της βάσης έδειξε ΚΑΝΕΝΑΝ πίνακα εργασιών, σταδίων ή ειδοποιήσεων.
--  Οι μόνοι πίνακες με «notification» στο όνομα ανήκουν στο WordPress
--  που μοιράζεται την ίδια βάση.
--
--  ΤΙ ΚΑΝΕΙ: τέσσερις νέους πίνακες και τους 7 τύπους εργασιών.
--  ΤΙ ΔΕΝ ΚΑΝΕΙ: δεν αγγίζει κανέναν υπάρχοντα πίνακα, δεν δημιουργεί
--  καμία εργασία. Η παραγωγή εργασιών έρχεται στη Φάση 2.
--
--  ΑΣΦΑΛΕΣ: μόνο CREATE TABLE IF NOT EXISTS και INSERT IGNORE. Καμία
--  ALTER, κανένα DELETE, καμία εγγραφή σε υπάρχουσα στήλη. Idempotent.
--
--  ΤΥΠΟΙ ΚΛΕΙΔΙΩΝ — ελεγμένοι στην παραγωγή, ΜΗΝ αλλάξουν αυθαίρετα:
--      4a_clients.id  = BIGINT  (signed, ΧΩΡΙΣ auto_increment)
--      4a_users.id    = INT     (signed, auto_increment)
--  Γι' αυτό client_id = BIGINT και τα user references = INT.
--
--  ΧΩΡΙΣ FOREIGN KEYS, σκόπιμα: κανένας πίνακας 4a_* δεν χρησιμοποιεί
--  FK σήμερα (π.χ. 4a_offers.client_id είναι απλό index). Κρατάμε το
--  ίδιο μοτίβο — ένα FK σε ορφανές εγγραφές θα έριχνε τη μετάπτωση.
-- =====================================================================

-- Τα ελληνικά labels περνούν από το αρχείο, όχι από γραμμή εντολών.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Τύποι εργασιών — πίνακας, όχι σταθερή λίστα στον κώδικα
--    Ο ιδιοκτήτης προσθέτει/απενεργοποιεί τύπους χωρίς deploy.
--
--    condition_key: πότε ΕΧΕΙ ΝΟΗΜΑ η εργασία. NULL = πάντα.
--                   has_cod / has_fuel = μόνο αν ο πελάτης τα έχει.
--    depends_on:    code άλλου τύπου που πρέπει να κλείσει πρώτα.
--    sla_hours:     όριο σε ώρες για το due_at. NULL = χωρίς όριο.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_task_types` (
  `code`          VARCHAR(40)  NOT NULL COMMENT 'open_code, cms_rates — σταθερό, μπαίνει στο 4a_tasks',
  `label`         VARCHAR(120) NOT NULL COMMENT 'τι βλέπει ο χρήστης',
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `condition_key` VARCHAR(40)      NULL COMMENT 'NULL | has_cod | has_fuel',
  `depends_on`    VARCHAR(40)      NULL COMMENT 'code άλλου τύπου — κλείνει πρώτο',
  `sla_hours`     INT              NULL COMMENT 'ώρες ως το due_at, NULL = χωρίς όριο',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  KEY `ix_active_sort` (`active`, `sort_order`),
  KEY `ix_depends_on`  (`depends_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Ποιος ΜΠΟΡΕΙ να κάνει τι, ανά χώρα
--    Χωριστά από τα δικαιώματα RBAC: το RBAC λέει ποιος βλέπει τη
--    σελίδα, αυτό λέει ποιος μπορεί να αναλάβει τη συγκεκριμένη
--    εργασία. Η χώρα είναι μέρος του κλειδιού — υπάλληλος μπορεί να
--    καταχωρεί τιμές CMS για Ελλάδα αλλά όχι για Κύπρο.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_user_task_skills` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT             NOT NULL COMMENT '-> 4a_users.id',
  `task_code`  VARCHAR(40)     NOT NULL COMMENT '-> 4a_task_types.code',
  `country`    ENUM('GR','CY','BOTH') NOT NULL DEFAULT 'BOTH',
  `granted_by` INT                 NULL COMMENT '-> 4a_users.id, NULL = μετάπτωση',
  `granted_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_task_country` (`user_id`, `task_code`, `country`),
  KEY `ix_task_country` (`task_code`, `country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Οι εργασίες
--    status: open       — δημιουργήθηκε, χωρίς ανάδοχο ή σε αναμονή
--            in_progress— κάποιος την ανέλαβε
--            paused     — σταμάτησε προσωρινά (paused_at)
--            done       — ολοκληρώθηκε
--            na         — δεν εφαρμόζεται σε αυτόν τον πελάτη
--    Το `na` υπάρχει ώστε να ΜΗΝ διαγράφεται εργασία που αποδείχθηκε
--    άσχετη: μένει ορατή με λόγο, αντί να εξαφανίζεται σιωπηλά.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_tasks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`    BIGINT          NOT NULL COMMENT '-> 4a_clients.id (BIGINT signed)',
  `task_code`    VARCHAR(40)     NOT NULL COMMENT '-> 4a_task_types.code',
  `assigned_to`  INT                 NULL COMMENT '-> 4a_users.id, NULL = αδιάθετη',
  `status`       ENUM('open','in_progress','paused','done','na') NOT NULL DEFAULT 'open',
  `created_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_at`       DATETIME            NULL COMMENT 'από sla_hours του τύπου',
  `paused_at`    DATETIME            NULL,
  `closed_at`    DATETIME            NULL COMMENT 'done ή na',
  `closed_by`    INT                 NULL COMMENT '-> 4a_users.id',
  `close_reason` VARCHAR(200)        NULL COMMENT 'υποχρεωτικό στο na, από το UI',
  `payload`      JSON                NULL COMMENT 'ό,τι χρειάζεται η εργασία: κωδικοί, ποσά',
  PRIMARY KEY (`id`),
  KEY `ix_assigned_status` (`assigned_to`, `status`),
  KEY `ix_client`         (`client_id`),
  KEY `ix_status_due`     (`status`, `due_at`),
  KEY `ix_task_code`      (`task_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Ιστορικό — τι συνέβη σε κάθε εργασία
--    Μόνο προσθήκες, ποτέ ενημέρωση. Το `event` μένει VARCHAR και όχι
--    ENUM: νέα είδη συμβάντων δεν πρέπει να απαιτούν ALTER σε πίνακα
--    που θα έχει τις περισσότερες γραμμές.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_task_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`    BIGINT UNSIGNED NOT NULL COMMENT '-> 4a_tasks.id',
  `event`      VARCHAR(30)     NOT NULL COMMENT 'created, assigned, started, paused, resumed, done, na, reopened, note',
  `actor_id`   INT                 NULL COMMENT '-> 4a_users.id, NULL = σύστημα',
  `note`       TEXT                NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_task_time` (`task_id`, `created_at`),
  KEY `ix_actor`     (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Οι 7 τύποι εργασιών
--    INSERT IGNORE και όχι ON DUPLICATE KEY UPDATE: αν ο ιδιοκτήτης
--    αλλάξει label ή σειρά από το UI, μια δεύτερη εκτέλεση δεν πρέπει
--    να τα γυρίσει πίσω. Διόρθωση seed γίνεται με ρητό UPDATE.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `4a_task_types`
  (`code`, `label`, `sort_order`, `condition_key`, `depends_on`, `sla_hours`) VALUES
  ('open_code',     'Άνοιγμα κωδικού συνεργασίας',      10, NULL,      NULL,        NULL),
  ('cms_rates',     'Καταχώρηση τιμών στο CMS',         20, NULL,      NULL,        NULL),
  ('cms_cod',       'Καταχώρηση COD στο CMS',           30, 'has_cod', 'cms_rates', NULL),
  ('cms_fuel',      'Καταχώρηση επίναυλου',             40, 'has_fuel','cms_rates', NULL),
  ('notify_client', 'Ενημέρωση πελάτη για τον κωδικό',  50, NULL,      'open_code', NULL),
  ('cod_form',      'Αποστολή εντύπου αντικαταβολών',   60, 'has_cod', NULL,        NULL),
  ('training',      'Εκμάθηση πλατφόρμας',              70, NULL,      NULL,        NULL);

-- ---------------------------------------------------------------------
-- 6. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `code`, `label`, `sort_order`, `condition_key`, `depends_on`, `active`
  FROM `4a_task_types` ORDER BY `sort_order`;

-- Κάθε depends_on πρέπει να δείχνει σε υπαρκτό code. Κενό = σωστό.
SELECT t.`code` AS `ΣΠΑΣΜΕΝΟ depends_on`, t.`depends_on`
  FROM `4a_task_types` t
  LEFT JOIN `4a_task_types` d ON d.`code` = t.`depends_on`
 WHERE t.`depends_on` IS NOT NULL AND d.`code` IS NULL;

SELECT COUNT(*) AS `ΤΥΠΟΙ`        FROM `4a_task_types`;
SELECT COUNT(*) AS `ΔΕΞΙΟΤΗΤΕΣ`   FROM `4a_user_task_skills`;
SELECT COUNT(*) AS `ΕΡΓΑΣΙΕΣ`     FROM `4a_tasks`;
SELECT COUNT(*) AS `ΣΥΜΒΑΝΤΑ`     FROM `4a_task_events`;
