-- 2026-09-25d_task_statuses_display.sql
-- Υπόμνημα χρωμάτων + badge που δείχνει ΕΠΟΜΕΝΟ ΒΗΜΑ, όχι ιδιοκτησία.
--
-- Η ΑΡΧΗ: το ΛΕΞΙΛΟΓΙΟ (ποιες λέξεις, τι χρώμα, τι εικονίδιο, τι σειρά)
-- ζει εδώ. Ο ΚΑΝΟΝΑΣ (ποια λέξη ισχύει τώρα) ζει στην tasks_lib.php.
-- Η οθόνη δεν ξέρει καμία ετικέτα και κανένα χρώμα.
--
-- ΨΕΥΔΟ-ΚΑΤΑΣΤΑΣΕΙΣ: το `ready` και το `locked` είναι ΥΠΟΛΟΓΙΣΜΟΙ, όχι
-- αποθηκευμένες τιμές. Μπαίνουν όμως στον ίδιο πίνακα, ώστε να έχουν
-- ετικέτα και χρώμα σαν όλες τις άλλες. Δεν κινδυνεύει τίποτα: η στήλη
-- 4a_tasks.status είναι ENUM('open','in_progress','paused','done','na')
-- και η βάση ΑΠΟΡΡΙΠΤΕΙ κάθε προσπάθεια να αποθηκευτούν ως πραγματική
-- κατάσταση. Η ψευδο-κατάσταση είναι δομικά ανίκανη να μολύνει τη λογική.

-- ── 1. icon ───────────────────────────────────────────────────────────
-- Προαιρετικό. Κενό = το υπόμνημα ζωγραφίζει χρωματιστή κουκκίδα από το
-- `color`. Έτσι μια νέα κατάσταση αύριο εμφανίζεται σωστά ΧΩΡΙΣ εικονίδιο
-- και χωρίς καμία αλλαγή κώδικα.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_statuses`
       ADD COLUMN `icon` VARCHAR(8) NULL
       COMMENT ''προαιρετικό εικονίδιο· κενό = χρωματιστή κουκκίδα''',
    'SELECT ''η στήλη icon υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_statuses'
    AND COLUMN_NAME  = 'icon'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Οι ετικέτες γίνονται ΚΑΤΑΣΤΑΣΕΙΣ, όχι ΓΕΓΟΝΟΤΑ ─────────────────
-- «Δημιουργία» και «Ανάθεση» περιγράφουν τι ΕΓΙΝΕ κάποτε. Το badge
-- πρέπει να λέει τι ΙΣΧΥΕΙ τώρα και τι περιμένει ο επόμενος άνθρωπος.
UPDATE `4a_task_statuses` SET `label` = 'Χωρίς ανάδοχο' WHERE `code` = 'open';
UPDATE `4a_task_statuses` SET `label` = 'Σε εξέλιξη'    WHERE `code` = 'in_progress';
UPDATE `4a_task_statuses` SET `label` = 'Ολοκληρωμένη'  WHERE `code` = 'done';

-- ── 3. Οι τρεις που λείπουν ───────────────────────────────────────────
--
-- paused: ΥΠΑΡΧΕΙ στο ENUM και στον κώδικα (status IN in_progress,paused)
--   αλλά ΔΕΝ είχε ποτέ γραμμή εδώ. Μέχρι σήμερα θα εμφανιζόταν ως ωμό
--   «paused» στην οθόνη. Δεν το προκάλεσε αυτή η αλλαγή, αλλά το υπόμνημα
--   θα το έκανε ορατό.
--
-- ΧΡΩΜΑΤΑ — καμία σύγκρουση με τα υπάρχοντα (#9c27b0 μοβ, #f0a000 κεχριμπάρι,
--   #9e9e9e γκρι, #cfcfcf ανοιχτό γκρι):
--   paused #00838f  teal — μόνη κυανή απόχρωση της παλέτας· «σε αναμονή»
--                   χωρίς να υπονοεί σφάλμα (κόκκινο) ή κλείσιμο (γκρι)
--   ready  #2e7d32  πράσινο — ΙΔΙΟ με το συμπαγές κουμπί «Ολοκλήρωση»
--                   όταν είναι έτοιμη· το badge και το κουμπί λένε το ίδιο
--   locked #607d8b  γκριζογάλανο — σβησμένο, αλλά με μπλε τόνο ώστε να
--                   ξεχωρίζει από τα γκρι του done/na
--
-- Και τα τρία έχουν φωτεινότητα < 150, άρα η textOn() βάζει λευκά γράμματα.
INSERT INTO `4a_task_statuses` (`code`,`label`,`color`,`sort_order`,`active`,`icon`)
VALUES ('paused','Σε παύση','#00838f',25,1,'⏸')
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `color`=VALUES(`color`),
                        `sort_order`=VALUES(`sort_order`), `icon`=VALUES(`icon`);

INSERT INTO `4a_task_statuses` (`code`,`label`,`color`,`sort_order`,`active`,`icon`)
VALUES ('ready','Έτοιμη για ολοκλήρωση','#2e7d32',28,1,NULL)
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `color`=VALUES(`color`),
                        `sort_order`=VALUES(`sort_order`);

INSERT INTO `4a_task_statuses` (`code`,`label`,`color`,`sort_order`,`active`,`icon`)
VALUES ('locked','Περιμένει','#607d8b',5,1,'🔒')
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `color`=VALUES(`color`),
                        `sort_order`=VALUES(`sort_order`), `icon`=VALUES(`icon`);

-- ── 4. Επαλήθευση ─────────────────────────────────────────────────────
SELECT `sort_order` AS srt, `code`, `label`, `color`,
       IFNULL(`icon`, '(κουκκίδα)') AS ico, `active`
  FROM `4a_task_statuses`
 ORDER BY `sort_order`;
