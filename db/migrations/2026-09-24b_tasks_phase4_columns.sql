-- =====================================================================
--  4a-pricing · Εργασίες Φάση 4: needs_attention και events.meta
--  Αρχείο: 2026-09-24b_tasks_phase4_columns.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql, 2026-09-24a
--  Σχέδιο: docs/tasks_phase4_spec.md §3γ, §3δ
--
--  ΓΙΑΤΙ ΧΩΡΙΣΤΟ ΑΡΧΕΙΟ ΑΠΟ ΤΟ 2026-09-24a:
--  Εκείνο δημιουργεί ΝΕΟΥΣ πίνακες — αν πάει στραβά, δεν έχει αγγίξει
--  τίποτα ζωντανό. Αυτό κάνει ALTER σε δύο πίνακες που ΧΡΗΣΙΜΟΠΟΙΟΥΝΤΑΙ
--  από τον κώδικα της Φάσης 3. Χωριστή εκτέλεση, χωριστή επαναφορά.
--
--  ΟΧΙ ALTER TABLE ... ADD COLUMN IF NOT EXISTS — είναι σύνταξη MariaDB
--  και σκάει σε MySQL. Ο server είναι MySQL 8.4.6. Χρησιμοποιείται το
--  μοτίβο information_schema + PREPARE, όπως στο 2026-09-15_shelf_labels.
--
--  ΣΥΜΒΑΤΟΤΗΤΑ ΜΕ ΤΟΝ ΖΩΝΤΑΝΟ ΚΩΔΙΚΑ:
--  Και οι δύο στήλες είναι NULL-able ή έχουν DEFAULT, και καμία δεν
--  μπαίνει σε INSERT της Φάσης 3. Το api/tasks_lib.php και το
--  api/tasks_create.php συνεχίζουν να δουλεύουν αμετάβλητα μετά από
--  αυτό το migration — μπορεί να τρέξει ΠΡΙΝ ανέβει κώδικας Φάσης 4.
--
--  ΑΣΦΑΛΕΣ: δύο ADD COLUMN με DEFAULT και ένα ADD KEY. Καμία διαγραφή,
--  καμία αλλαγή υπάρχουσας στήλης. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. 4a_tasks.needs_attention
--
--    ΓΙΑΤΙ ΣΤΗΛΗ ΚΑΙ ΟΧΙ ΥΠΟΛΟΓΙΣΜΟΣ: το κόκκινο σήμα το χρειάζεται σε
--    ΚΑΘΕ φόρτωση σελίδας. Ο υπολογισμός «δεν έμεινε υποψήφιος» είναι
--    NOT EXISTS πάνω σε δεξιότητες ΚΑΙ απορρίψεις ΚΑΙ ενεργούς χρήστες —
--    πολύ ακριβός για να τρέχει σε κάθε άνοιγμα της σελίδας πελατών.
--    Γίνεται 0 μόλις κάποιος αναλάβει την εργασία.
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'needs_attention') = 0,
  'ALTER TABLE `4a_tasks`
     ADD COLUMN `needs_attention` TINYINT(1) NOT NULL DEFAULT 0
       COMMENT ''απορρίφθηκε και δεν έμεινε υποψήφιος — ανάβει το κόκκινο σήμα''
       AFTER `status`',
  'SELECT ''η στήλη needs_attention υπάρχει ήδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Το ευρετήριο του σήματος: COUNT(*) WHERE needs_attention=1 AND status='open'
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND INDEX_NAME   = 'ix_attention') = 0,
  'ALTER TABLE `4a_tasks` ADD KEY `ix_attention` (`needs_attention`, `status`)',
  'SELECT ''το ix_attention υπάρχει ήδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. 4a_task_events.meta
--
--    Το `note` μένει ΑΝΘΡΩΠΙΝΟ κείμενο — αυτό που διαβάζει ο χρήστης στο
--    ιστορικό. Το `meta` κρατά τα ΔΟΜΗΜΕΝΑ:
--        auto_assigned -> {"to_user": 4, "why": "…"}
--        rejected      -> {"reason_code": "workload"}
--        stolen        -> {"from_user": 3, "to_user": 4}
--
--    Χωρίς αυτό, το «ποιος τράβηξε από ποιον» θα έβγαινε με ανάλυση
--    κειμένου από το note — δηλαδή θα έσπαγε την πρώτη φορά που κάποιος
--    άλλαζε μια λέξη στο μήνυμα.
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_task_events'
                   AND COLUMN_NAME  = 'meta') = 0,
  'ALTER TABLE `4a_task_events`
     ADD COLUMN `meta` JSON NULL
       COMMENT ''δομημένα: to_user, from_user, reason_code, why''
       AFTER `note`',
  'SELECT ''η στήλη meta υπάρχει ήδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = '4a_tasks'        AND COLUMN_NAME = 'needs_attention')
     OR (TABLE_NAME = '4a_task_events'  AND COLUMN_NAME = 'meta'))
 ORDER BY TABLE_NAME, COLUMN_NAME;

SELECT `INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_tasks'
   AND INDEX_NAME = 'ix_attention'
 ORDER BY `SEQ_IN_INDEX`;

-- Καμία υπάρχουσα εργασία δεν πρέπει να σημανθεί από τη μετάπτωση.
SELECT COUNT(*) AS `ΜΕ_ΣΗΜΑΝΣΗ` FROM `4a_tasks` WHERE `needs_attention` = 1;

-- Το meta ξεκινά NULL παντού.
SELECT COUNT(*) AS `EVENTS_ΜΕ_META` FROM `4a_task_events` WHERE `meta` IS NOT NULL;
