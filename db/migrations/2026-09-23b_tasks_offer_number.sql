-- =====================================================================
--  4a-pricing · Εργασίες: αριθμός προσφοράς + μοναδικότητα
--  Αρχείο: 2026-09-23b_tasks_offer_number.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Ο ίδιος πελάτης με ΝΕΑ προσφορά πρέπει να ξαναπάρει εργασίες. Χωρίς
--  τον αριθμό προσφοράς στο κλειδί, ένα UNIQUE(client_id, task_code) θα
--  απέκλειε για πάντα τη δεύτερη ανάθεση — ανανέωση συμβολαίου δεν θα
--  παρήγαγε ποτέ εργασίες.
--
--  ΓΙΑΤΙ NOT NULL DEFAULT '' ΚΑΙ ΟΧΙ NULL:
--  Στη MySQL δύο NULL δεν θεωρούνται ίσα σε UNIQUE index. Με NULL-able
--  στήλη, το UNIQUE παρακάτω θα προστάτευε ΜΟΝΟ τις γραμμές που έχουν
--  αριθμό, και οι πελάτες χωρίς προσφορά θα έπαιρναν διπλές εργασίες σε
--  κάθε αποθήκευση:
--
--      INSERT (5, 'cms_rates', NULL)   -- περνά
--      INSERT (5, 'cms_rates', NULL)   -- ΠΕΡΝΑ ΚΑΙ ΑΥΤΟ  <-- το πρόβλημα
--      INSERT (5, 'cms_rates', '')     -- περνά
--      INSERT (5, 'cms_rates', '')     -- απορρίπτεται σωστά
--
--  Με '' το κλειδί καλύπτει και την περίπτωση «καμία προσφορά ακόμα»:
--  ένα σετ εργασιών τώρα, και νέο σετ όταν εκδοθεί προσφορά.
--
--  ΚΑΝΟΝΙΚΟΠΟΙΗΣΗ ΣΤΗΝ ΠΗΓΗ — ΜΗ ΤΟ ΧΑΣΕΤΕ:
--  Το 4a_clients.offer_number ΔΕΝ είναι αξιόπιστο ως έχει. Πριν γραφτεί
--  στο 4a_tasks.offer_number πρέπει να γίνει '' σε ΚΑΘΕ μία από αυτές:
--        NULL   |   ''   |   μόνο κενά   |   '—' (em-dash, U+2014)
--
--  Το em-dash δεν είναι θεωρητικό: το ίδιο UI μοτίβο το έχει ήδη γράψει
--  σε 26 από τους 46 πελάτες στη στήλη `account` (έλεγχος 23/09). Στο
--  offer_number δεν εμφανίζεται ακόμα — αλλά η ίδια φόρμα το παράγει.
--  Χωρίς κανονικοποίηση, '—' και '' θα θεωρούνταν διαφορετικές προσφορές
--  και ο πελάτης θα έπαιρνε δεύτερο σετ εργασιών.
--
--  ΦΑΣΗ 2 — ΕΚΚΡΕΜΕΙ ΣΤΟΝ PHP ΚΩΔΙΚΑ:
--  Ο κώδικας που δημιουργεί τις εργασίες (api/clients.php, action=save)
--  πρέπει να εφαρμόζει την ΙΔΙΑ κανονικοποίηση πριν το INSERT:
--      $on = trim((string)($client['offer_number'] ?? ''));
--      if ($on === '—') $on = '';
--  Η βάση δεν μπορεί να το επιβάλει — το DEFAULT '' πιάνει μόνο τη
--  στήλη που λείπει, όχι το '—' που στέλνεται ρητά.
--
--  ΔΕΔΟΜΕΝΑ ΠΑΡΑΓΩΓΗΣ (23/09): 21 πελάτες σε status 'accepted'. Οι 19
--  έχουν αριθμό, οι 2 έχουν '' (AIOLOS/MOULNTIS, ANO TELEIA KOINSEP) —
--  και οι δύο με κανονικό AccountNo. Η περίπτωση είναι υπαρκτή.
--
--  ΑΣΦΑΛΕΣ: ο 4a_tasks είναι άδειος (0 γραμμές). Μόνο προσθήκη στήλης
--  και ευρετηρίου. Idempotent μέσω information_schema — ΟΧΙ μέσω
--  ALTER TABLE ... IF NOT EXISTS, που είναι σύνταξη MariaDB και σκάει
--  σε MySQL. Ο server είναι MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Η στήλη — ίδιος τύπος με 4a_offers.offer_number / 4a_clients.offer_number
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND COLUMN_NAME  = 'offer_number') = 0,
  'ALTER TABLE `4a_tasks`
     ADD COLUMN `offer_number` VARCHAR(20) NOT NULL DEFAULT ''''
       COMMENT ''4a_clients.offer_number κανονικοποιημένο: NULL, κενό και em-dash γινονται κενο''
       AFTER `task_code`',
  'SELECT ''η στήλη offer_number υπάρχει ήδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 2. Μοναδικότητα ανά πελάτη + τύπο + προσφορά
--    Το ξεχωριστό ix_client μένει: το UNIQUE ξεκινά από client_id, οπότε
--    τεχνικά το καλύπτει, αλλά δεν το πειράζουμε σε αυτή τη μετάπτωση.
-- ---------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = '4a_tasks'
                   AND INDEX_NAME   = 'uq_client_task_offer') = 0,
  'ALTER TABLE `4a_tasks`
     ADD UNIQUE KEY `uq_client_task_offer` (`client_id`, `task_code`, `offer_number`)',
  'SELECT ''το uq_client_task_offer υπάρχει ήδη'' AS `ΠΑΡΑΛΕΙΨΗ`');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- 3. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `COLUMN_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_tasks'
   AND COLUMN_NAME = 'offer_number';

SELECT `INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `NON_UNIQUE`
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_tasks'
   AND INDEX_NAME = 'uq_client_task_offer'
 ORDER BY `SEQ_IN_INDEX`;

-- Πρέπει να παραμείνει 0 — η μετάπτωση δεν δημιουργεί εργασίες.
SELECT COUNT(*) AS `ΕΡΓΑΣΙΕΣ` FROM `4a_tasks`;
