-- =====================================================================
--  4a-pricing · Εργασίες: cod_form και training περιμένουν τον κωδικό
--  Αρχείο: 2026-09-23g_cod_form_training_depends.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql, 2026-09-23e
--
--  ΓΙΑΤΙ:
--  Ούτε το έντυπο αντικαταβολών ούτε η εκμάθηση πλατφόρμας έχουν νόημα
--  πριν αποκτήσει ο πελάτης κωδικό συνεργασίας — και τα δύο τον
--  προϋποθέτουν για να σταλούν ή να γίνουν.
--
--  Η ΑΛΥΣΙΔΑ ΜΕΤΑ:
--      open_code     ← —            ΜΟΝΑΔΙΚΗ ΡΙΖΑ
--      cms_rates     ← open_code
--      cms_cod       ← cms_rates
--      cms_fuel      ← cms_rates
--      notify_client ← open_code
--      cod_form      ← open_code    (ήταν —)
--      training      ← open_code    (ήταν —)
--
--  ΣΥΝΕΠΕΙΑ ΠΟΥ ΠΡΕΠΕΙ ΝΑ ΞΕΡΕΤΕ:
--  Όταν ένας πελάτης γίνει accepted, ΜΟΝΟ το open_code θα είναι
--  ξεκλείδωτο. Και οι έξι υπόλοιπες θα εμφανίζονται με 🔒 μέχρι να
--  κλείσει. Πριν από αυτή την αλλαγή, τα cod_form και training
--  μπορούσαν να ξεκινήσουν παράλληλα.
--
--  ΜΟΝΑΔΙΚΗ ΡΙΖΑ — ΣΗΜΕΙΟ ΕΥΘΡΑΥΣΤΟΤΗΤΑΣ:
--  Το open_code γίνεται το μοναδικό σημείο εκκίνησης. Αν κάποτε τεθεί
--  active = 0, δεν θα δημιουργείται πλέον ως εργασία — και επειδή το
--  κλείδωμα απαιτεί status IN ('done','na') σε ΥΠΑΡΚΤΗ εργασία, ΟΛΕΣ οι
--  υπόλοιπες θα έμεναν μόνιμα κλειδωμένες, σιωπηλά. Ο έλεγχος στο τέλος
--  μετρά τις ρίζες ώστε να φαίνεται.
--
--  ΑΣΦΑΛΕΣ: ένα UPDATE σε δύο γραμμές μεταδεδομένων, με ρητό WHERE στα
--  code. Καμία ALTER, κανένα DELETE. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Η αλλαγή
-- ---------------------------------------------------------------------
UPDATE `4a_task_types`
   SET `depends_on` = 'open_code'
 WHERE `code` IN ('cod_form', 'training');

-- ---------------------------------------------------------------------
-- 2. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `code`, `label`, `sort_order`, `condition_key`, `depends_on`, `active`
  FROM `4a_task_types` ORDER BY `sort_order`;

-- Κάθε depends_on πρέπει να δείχνει σε υπαρκτό code. Κενό = σωστό.
SELECT t.`code` AS `ΣΠΑΣΜΕΝΟ_depends_on`, t.`depends_on`
  FROM `4a_task_types` t
  LEFT JOIN `4a_task_types` d ON d.`code` = t.`depends_on`
 WHERE t.`depends_on` IS NOT NULL AND d.`code` IS NULL;

-- Αυτοαναφορά. Κενό = σωστό.
SELECT `code` AS `ΑΥΤΟΑΝΑΦΟΡΑ`
  FROM `4a_task_types` WHERE `depends_on` = `code`;

-- Κύκλος δύο βημάτων. Κενό = σωστό.
SELECT a.`code` AS `ΚΥΚΛΟΣ_A`, b.`code` AS `ΚΥΚΛΟΣ_B`
  FROM `4a_task_types` a
  JOIN `4a_task_types` b ON b.`code` = a.`depends_on`
 WHERE b.`depends_on` = a.`code`;

-- Κύκλος τριών βημάτων. Κενό = σωστό.
SELECT a.`code` AS `ΚΥΚΛΟΣ3_A`, b.`code` AS `ΚΥΚΛΟΣ3_B`, c.`code` AS `ΚΥΚΛΟΣ3_C`
  FROM `4a_task_types` a
  JOIN `4a_task_types` b ON b.`code` = a.`depends_on`
  JOIN `4a_task_types` c ON c.`code` = b.`depends_on`
 WHERE c.`depends_on` = a.`code`;

-- Ρίζες: πρέπει να είναι τουλάχιστον 1, αλλιώς τίποτα δεν ξεκινά ποτέ.
-- Μετά από αυτό το migration αναμένεται ΑΚΡΙΒΩΣ 1 (το open_code).
SELECT `code` AS `ΡΙΖΑ`
  FROM `4a_task_types` WHERE `active` = 1 AND `depends_on` IS NULL;

-- Τι θα είναι ξεκλείδωτο σε νέο πελάτη: μόνο οι ρίζες.
SELECT COUNT(*) AS `ΞΕΚΛΕΙΔΩΤΕΣ_ΣΤΗΝ_ΑΡΧΗ`
  FROM `4a_task_types` WHERE `active` = 1 AND `depends_on` IS NULL;
