-- =====================================================================
--  4a-pricing · Εργασίες: το cms_rates εξαρτάται από το open_code
--  Αρχείο: 2026-09-23e_cms_rates_depends.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Διόρθωση κανόνα από τον ιδιοκτήτη (23/09): τα αρχεία CMS import
--  περιέχουν τον κωδικό συνεργασίας του πελάτη. Άρα η καταχώρηση τιμών
--  στο CMS ΔΕΝ μπορεί να γίνει πριν ανοίξει ο κωδικός.
--
--  ΤΙ ΑΛΛΑΖΕΙ ΣΤΗΝ ΑΛΥΣΙΔΑ:
--      πριν                          μετά
--      open_code     ← —             open_code     ← —
--      cms_rates     ← —             cms_rates     ← open_code
--      cms_cod       ← cms_rates     cms_cod       ← cms_rates
--      cms_fuel      ← cms_rates     cms_fuel      ← cms_rates
--      notify_client ← open_code     notify_client ← open_code
--      cod_form      ← —             cod_form      ← —
--      training      ← —             training      ← —
--
--  Η αλυσίδα γίνεται τριών επιπέδων: cms_cod → cms_rates → open_code.
--  ΠΡΟΣΟΧΗ: ο υπολογισμός του `locked` στο api/tasks_lib.php:35-41
--  ελέγχει ΕΝΑ επίπεδο. Εδώ αρκεί, επειδή το cms_rates δεν μπορεί να
--  κλείσει όσο είναι κλειδωμένο — άρα η μεταβατικότητα προκύπτει από
--  μόνη της. Βλ. όμως τη σημείωση για το tasks_assign παρακάτω.
--
--  ΑΣΦΑΛΕΣ: ένα UPDATE σε μία γραμμή μεταδεδομένων, με ρητό WHERE στο
--  code. Καμία ALTER, κανένα DELETE. Idempotent — δεύτερη εκτέλεση
--  γράφει την ίδια τιμή.
--
--  ΔΕΝ ΥΠΑΡΧΕΙ ΕΛΕΓΧΟΣ ΚΥΚΛΟΥ ΠΟΥΘΕΝΑ (εντοπίστηκε 23/09):
--  ούτε στη βάση (το `depends_on` είναι απλό VARCHAR χωρίς FK ή CHECK),
--  ούτε στον κώδικα. Μια κυκλική αλυσίδα δεν θα κρεμούσε τίποτα — το
--  SQL ελέγχει ένα επίπεδο — αλλά θα κλείδωνε ΜΟΝΙΜΑ και σιωπηλά όλες
--  τις εργασίες του κύκλου: καθεμία θα περίμενε την επόμενη.
--  Ο έλεγχος στο τέλος αυτού του αρχείου πιάνει αυτοαναφορά και κύκλο
--  δύο βημάτων, που είναι τα ρεαλιστικά λάθη με 7 τύπους.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Η αλλαγή
-- ---------------------------------------------------------------------
UPDATE `4a_task_types`
   SET `depends_on` = 'open_code'
 WHERE `code` = 'cms_rates';

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

-- Αυτοαναφορά: X εξαρτάται από τον εαυτό του. Κενό = σωστό.
SELECT `code` AS `ΑΥΤΟΑΝΑΦΟΡΑ`
  FROM `4a_task_types` WHERE `depends_on` = `code`;

-- Κύκλος δύο βημάτων: A -> B και B -> A. Κενό = σωστό.
SELECT a.`code` AS `ΚΥΚΛΟΣ_A`, b.`code` AS `ΚΥΚΛΟΣ_B`
  FROM `4a_task_types` a
  JOIN `4a_task_types` b ON b.`code` = a.`depends_on`
 WHERE b.`depends_on` = a.`code`;

-- Κύκλος τριών βημάτων: A -> B -> C -> A. Κενό = σωστό.
SELECT a.`code` AS `ΚΥΚΛΟΣ3_A`, b.`code` AS `ΚΥΚΛΟΣ3_B`, c.`code` AS `ΚΥΚΛΟΣ3_C`
  FROM `4a_task_types` a
  JOIN `4a_task_types` b ON b.`code` = a.`depends_on`
  JOIN `4a_task_types` c ON c.`code` = b.`depends_on`
 WHERE c.`depends_on` = a.`code`;

-- Οι ρίζες της αλυσίδας — τουλάχιστον μία πρέπει να υπάρχει, αλλιώς
-- τίποτα δεν μπορεί να ξεκινήσει ποτέ.
SELECT COUNT(*) AS `ΡΙΖΕΣ_χωρίς_εξάρτηση`
  FROM `4a_task_types` WHERE `active` = 1 AND `depends_on` IS NULL;
