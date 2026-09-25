-- 2026-09-25c_task_types_ready.sql
-- «Είναι έτοιμη να ολοκληρωθεί αυτή η εργασία;»
--
-- Η οθόνη καθοδηγεί, ΔΕΝ εμποδίζει: όταν η προϋπόθεση δεν πληρούται, το
-- κουμπί «Ολοκλήρωση» ξεθωριάζει και εξηγεί γιατί — αλλά πατιέται
-- κανονικά. Ο άνθρωπος ξέρει πράγματα που η βάση δεν ξέρει.
--
-- ΚΑΝΟΝΑΣ SERVER: MySQL 8.4.6 — ΟΧΙ `ADD COLUMN IF NOT EXISTS`.

-- ── 1. ready_check ────────────────────────────────────────────────────
--
-- ΡΗΤΟΣ ΚΑΝΟΝΑΣ: το ready_check είναι ΚΛΕΙΔΙ, ΠΟΤΕ ΕΚΦΡΑΣΗ.
--
-- Επιτρεπτό:   'client_account'
-- ΑΠΑΓΟΡΕΥΕΤΑΙ: 'account IS NOT NULL AND account <> ''—'''
--
-- Ο πειρασμός θα υπάρξει: μοιάζει πιο ευέλικτο να γραφτεί η συνθήκη
-- κατευθείαν στη στήλη. Τη στιγμή που θα γίνει, η βάση γίνεται κώδικας
-- χωρίς έλεγχο σύνταξης, χωρίς δοκιμές, χωρίς ιστορικό αλλαγών — και σε
-- αντίθεση με ένα URL, μια αυθαίρετη έκφραση ΔΕΝ μπορεί να επικυρωθεί.
-- Κάθε νέο κλειδί υλοποιείται στην tasks_ready() του tasks_lib.php, με
-- δικό του stub έλεγχο.
--
-- Άγνωστο κλειδί -> ready = true (fail open) + error_log. Ένας κανόνας
-- που δεν υλοποιήθηκε δεν πρέπει να ξεθωριάζει δουλειά που μπορεί κάλλιστα
-- να είναι έτοιμη.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_types`
       ADD COLUMN `ready_check` VARCHAR(40) NULL
       COMMENT ''ΚΛΕΙΔΙ προϋπόθεσης, ΠΟΤΕ έκφραση· NULL = πάντα έτοιμη''',
    'SELECT ''η στήλη ready_check υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_types'
    AND COLUMN_NAME  = 'ready_check'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. ready_hint ─────────────────────────────────────────────────────
-- Κείμενο προς τον χρήστη -> ανήκει στη βάση, όπως το action_label.
-- Κενό = γενικό εφεδρικό κείμενο από την PHP.
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `4a_task_types`
       ADD COLUMN `ready_hint` VARCHAR(120) NULL
       COMMENT ''οδηγία όταν η εργασία ΔΕΝ είναι έτοιμη· κενό = γενικό κείμενο''',
    'SELECT ''η στήλη ready_hint υπάρχει ήδη'' AS msg'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = '4a_task_types'
    AND COLUMN_NAME  = 'ready_hint'
);
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 3. Γέμισμα — ΜΟΝΟ η open_code ─────────────────────────────────────
UPDATE `4a_task_types`
   SET `ready_check` = 'client_account',
       `ready_hint`  = 'Καταχωρήστε πρώτα τον κωδικό συνεργασίας'
 WHERE `code` = 'open_code';

-- ── 4. Επαλήθευση ─────────────────────────────────────────────────────
SELECT `code`,
       IFNULL(`ready_check`, '—') AS chk,
       IFNULL(`ready_hint`,  '—') AS hint
  FROM `4a_task_types`
 ORDER BY `sort_order`;
