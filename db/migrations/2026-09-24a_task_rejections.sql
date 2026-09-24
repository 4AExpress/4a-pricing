-- =====================================================================
--  4a-pricing · Εργασίες Φάση 4: απόρριψη με κωδικοποιημένο λόγο
--  Αρχείο: 2026-09-24a_task_rejections.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--  Σχέδιο: docs/tasks_phase4_spec.md §3α, §3β
--
--  ΓΙΑΤΙ:
--  Ο ανάδοχος πρέπει να μπορεί να απορρίψει εργασία με λόγο που
--  ΜΕΤΡΙΕΤΑΙ. Το σημερινό close_reason είναι ελεύθερο varchar(200) και
--  δεν αθροίζεται σε τίποτα.
--
--  ΔΥΟ ΠΙΝΑΚΕΣ, ΔΥΟ ΔΟΥΛΕΙΕΣ:
--    4a_task_reject_reasons — ΤΙ λόγοι υπάρχουν (μεταδεδομένα)
--    4a_task_rejections     — ΠΟΙΟΣ απέρριψε ΤΙ και γιατί (γεγονότα)
--
--  ΤΟ UNIQUE(task_id, user_id) ΕΙΝΑΙ Ο ΚΑΝΟΝΑΣ, ΟΧΙ ΣΧΟΛΙΟ:
--  «ποτέ ξανά στον ίδιο» επιβάλλεται από τη βάση. Ο υπολογισμός των
--  δικαιούχων κάνει NOT IN πάνω σε αυτόν ακριβώς τον πίνακα. Αν το
--  κρατούσαμε μέσα στα events (όπου το event είναι ελεύθερο varchar),
--  το φίλτρο θα στηριζόταν σε ανάλυση κειμένου.
--
--  ΧΩΡΙΣΤΟΣ ΠΙΝΑΚΑΣ ΚΑΙ ΟΧΙ ΣΤΗΛΗ ΣΤΟ 4a_tasks: οι απορρίψεις είναι
--  πολλές ανά εργασία — μία ανά υποψήφιο που είπε όχι.
--
--  ΧΩΡΙΣ FOREIGN KEYS, όπως κάθε άλλος πίνακας 4a_*.
--  ΤΥΠΟΙ: task_id = BIGINT UNSIGNED (4a_tasks.id), user_id = INT
--  (4a_users.id). Ελεγμένοι στην παραγωγή, ΜΗΝ αλλάξουν αυθαίρετα.
--
--  ΑΣΦΑΛΕΣ: μόνο CREATE TABLE IF NOT EXISTS και INSERT. Καμία ALTER σε
--  υπάρχοντα πίνακα, κανένα DELETE. Idempotent.
--  (Οι ALTER είναι χωριστά, στο 2026-09-24b.)
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Οι λόγοι — πίνακας, όχι σταθερές στον κώδικα
--
--    task_code: NULL = ισχύει για κάθε τύπο εργασίας. Η στήλη υπάρχει
--    ώστε να μπορεί αργότερα να προστεθεί λόγος ειδικός για έναν τύπο,
--    χωρίς δεύτερο πίνακα. Σήμερα ΟΛΟΙ είναι καθολικοί.
--
--    requires_note: ο κανόνας «αυτός ο λόγος θέλει εξήγηση» ζει ΕΔΩ και
--    όχι ως if ($code === 'other') στην PHP. Αλλιώς κάθε νέος λόγος που
--    χρειάζεται σχόλιο θα απαιτούσε deploy.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_task_reject_reasons` (
  `code`          VARCHAR(40)  NOT NULL COMMENT 'workload, absent, … — μπαίνει στο 4a_task_rejections',
  `label`         VARCHAR(120) NOT NULL COMMENT 'τι βλέπει ο χρήστης',
  `task_code`     VARCHAR(40)      NULL COMMENT 'NULL = για κάθε τύπο εργασίας',
  `requires_note` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = το σχόλιο είναι υποχρεωτικό',
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`),
  KEY `ix_task_active` (`task_code`, `active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Ποιος απέρριψε τι
--
--    note: προαιρετικό γενικά, ΥΠΟΧΡΕΩΤΙΚΟ όταν ο λόγος έχει
--    requires_note = 1. Η βάση δεν μπορεί να το επιβάλει (εξαρτάται από
--    άλλη γραμμή άλλου πίνακα) — το επιβάλλει το api/tasks_lib.php.
--    Σημειώνεται εδώ ώστε να μη θεωρηθεί ξεχασμένο.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_task_rejections` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id`     BIGINT UNSIGNED NOT NULL COMMENT '-> 4a_tasks.id',
  `user_id`     INT             NOT NULL COMMENT '-> 4a_users.id, ποιος απέρριψε',
  `reason_code` VARCHAR(40)     NOT NULL COMMENT '-> 4a_task_reject_reasons.code',
  `note`        VARCHAR(200)        NULL COMMENT 'υποχρεωτικό αν requires_note = 1',
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_user` (`task_id`, `user_id`),
  KEY `ix_task`   (`task_id`),
  KEY `ix_reason` (`reason_code`, `created_at`),
  KEY `ix_user`   (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Οι πέντε λόγοι (απόφαση 24/09)
--    ON DUPLICATE KEY UPDATE και όχι INSERT IGNORE: οι ετικέτες και η
--    σειρά είναι αποφάσεις που θέλουμε να διορθώνονται με επανεκτέλεση.
-- ---------------------------------------------------------------------
INSERT INTO `4a_task_reject_reasons`
  (`code`, `label`, `task_code`, `requires_note`, `sort_order`, `active`) VALUES
  ('workload',     'Φόρτος εργασίας',                NULL, 0, 10, 1),
  ('absent',       'Απουσία / άδεια',                NULL, 0, 20, 1),
  ('no_knowledge', 'Δεν γνωρίζω τη διαδικασία',      NULL, 0, 30, 1),
  ('wrong_person', 'Δεν είναι δική μου αρμοδιότητα', NULL, 0, 40, 1),
  ('other',        'Άλλο',                           NULL, 1, 50, 1)
ON DUPLICATE KEY UPDATE
  `label`         = VALUES(`label`),
  `task_code`     = VALUES(`task_code`),
  `requires_note` = VALUES(`requires_note`),
  `sort_order`    = VALUES(`sort_order`),
  `active`        = VALUES(`active`);

-- ---------------------------------------------------------------------
-- 4. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `code`, `label`, `task_code`, `requires_note`, `sort_order`, `active`
  FROM `4a_task_reject_reasons` ORDER BY `sort_order`;

-- Αναμένονται 5, όλοι καθολικοί, ΑΚΡΙΒΩΣ ένας με requires_note.
SELECT COUNT(*) AS `ΛΟΓΟΙ`,
       SUM(`task_code` IS NULL)  AS `ΚΑΘΟΛΙΚΟΙ`,
       SUM(`requires_note` = 1)  AS `ΜΕ_ΥΠΟΧΡΕΩΤΙΚΟ_ΣΧΟΛΙΟ`
  FROM `4a_task_reject_reasons` WHERE `active` = 1;

-- task_code που δεν αντιστοιχεί σε υπαρκτό τύπο. Κενό = σωστό.
SELECT r.`code` AS `ΑΓΝΩΣΤΟΣ_ΤΥΠΟΣ`, r.`task_code`
  FROM `4a_task_reject_reasons` r
  LEFT JOIN `4a_task_types` t ON t.`code` = r.`task_code`
 WHERE r.`task_code` IS NOT NULL AND t.`code` IS NULL;

-- Ο πίνακας απορρίψεων ξεκινά άδειος.
SELECT COUNT(*) AS `ΑΠΟΡΡΙΨΕΙΣ` FROM `4a_task_rejections`;
