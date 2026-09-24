-- =====================================================================
--  4a-pricing · Εργασίες: καταστάσεις ως πίνακας, με χρώμα
--  Αρχείο: 2026-09-23f_task_statuses.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Οι ετικέτες και τα χρώματα των καταστάσεων ήταν σταθερά μέσα στο
--  frontend/tasks.html (ST_LABELS και κανόνες .st.open, .st.done κ.λπ.).
--  Θα προστεθούν κι άλλα στάδια αργότερα· κάθε νέο θα απαιτούσε deploy
--  σε HTML. Ο πίνακας τα βγάζει από τον κώδικα.
--
--  ΚΑΝΕΝΑ ΧΡΩΜΑ ΔΕΝ ΜΕΝΕΙ ΣΤΟΝ ΚΩΔΙΚΑ. Το tasks.html διαβάζει χρώμα και
--  ετικέτα από εδώ και τα εφαρμόζει inline. Το χρώμα κειμένου ΔΕΝ
--  αποθηκεύεται — υπολογίζεται από τη φωτεινότητα του φόντου, ώστε ένα
--  νέο στάδιο να μη χρειάζεται δεύτερη απόφαση.
--
--  ΠΡΟΣΟΧΗ — ΔΥΟ ΠΗΓΕΣ ΓΙΑ ΤΙΣ ΚΑΤΑΣΤΑΣΕΙΣ:
--  Το 4a_tasks.status είναι ENUM('open','in_progress','paused','done','na').
--  Αυτός ο πίνακας είναι ΜΟΝΟ εμφάνιση — δεν επιβάλλει τίποτα. Νέο
--  στάδιο χρειάζεται ΚΑΙ γραμμή εδώ ΚΑΙ ALTER στο ENUM, αλλιώς η βάση θα
--  απορρίψει την τιμή. Ο έλεγχος στο τέλος συγκρίνει τα δύο σύνολα.
--
--  ΤΟ 'paused' ΔΕΝ ΜΠΑΙΝΕΙ: υπάρχει στο ENUM αλλά καμία ενέργεια δεν το
--  θέτει (βλ. docs/tasks_phase3_spec.md §6). Θα προστεθεί όταν αποκτήσει
--  ενέργεια. Ο έλεγχος παρακάτω το αναδεικνύει αντί να το κρύβει.
--
--  ΑΣΦΑΛΕΣ: CREATE TABLE IF NOT EXISTS και INSERT ... ON DUPLICATE KEY
--  UPDATE. Καμία ALTER, κανένα DELETE. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Ο πίνακας
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_task_statuses` (
  `code`       VARCHAR(20) NOT NULL COMMENT 'ίδιο με 4a_tasks.status',
  `label`      VARCHAR(60) NOT NULL COMMENT 'τι βλέπει ο χρήστης',
  `color`      CHAR(7)     NOT NULL COMMENT 'φόντο badge, #rrggbb',
  `sort_order` INT         NOT NULL DEFAULT 0,
  `active`     TINYINT(1)  NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  KEY `ix_active_sort` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Οι τέσσερις καταστάσεις
--    ON DUPLICATE KEY UPDATE και όχι INSERT IGNORE: τα χρώματα είναι
--    απόφαση σχεδιασμού που θέλουμε να μπορεί να διορθωθεί με
--    επανεκτέλεση. Οι ετικέτες δεν αλλάζουν από UI σήμερα.
-- ---------------------------------------------------------------------
INSERT INTO `4a_task_statuses` (`code`, `label`, `color`, `sort_order`, `active`) VALUES
  ('open',        'Δημιουργία',        '#9c27b0', 10, 1),
  ('in_progress', 'Ανάθεση',           '#f0a000', 20, 1),
  ('done',        'Ολοκλήρωση',        '#9e9e9e', 30, 1),
  ('na',          'Δεν εφαρμόζεται',   '#cfcfcf', 40, 1)
ON DUPLICATE KEY UPDATE
  `label` = VALUES(`label`), `color` = VALUES(`color`),
  `sort_order` = VALUES(`sort_order`), `active` = VALUES(`active`);

-- ---------------------------------------------------------------------
-- 3. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `code`, `label`, `color`, `sort_order`, `active`
  FROM `4a_task_statuses` ORDER BY `sort_order`;

-- Κάθε χρώμα πρέπει να είναι #rrggbb. Κενό = σωστό.
SELECT `code` AS `ΑΚΥΡΟ_ΧΡΩΜΑ`, `color`
  FROM `4a_task_statuses`
 WHERE `color` NOT REGEXP '^#[0-9a-fA-F]{6}$';

-- Τιμές του ENUM που ΔΕΝ έχουν γραμμή εδώ: θα εμφανίζονταν άχρωμες.
-- Σήμερα αναμένεται ΜΟΝΟ το 'paused'.
SELECT 'paused' AS `ENUM_ΧΩΡΙΣ_ΓΡΑΜΜΗ`
 WHERE NOT EXISTS (SELECT 1 FROM `4a_task_statuses` WHERE `code` = 'paused');

-- Καταστάσεις σε χρήση που λείπουν από τον πίνακα. Κενό = σωστό.
SELECT t.`status` AS `ΣΕ_ΧΡΗΣΗ_ΧΩΡΙΣ_ΓΡΑΜΜΗ`, COUNT(*) AS n
  FROM `4a_tasks` t
  LEFT JOIN `4a_task_statuses` s ON s.`code` = t.`status`
 WHERE s.`code` IS NULL
 GROUP BY t.`status`;
