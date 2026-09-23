-- =====================================================================
--  4a-pricing · Εργασίες: αρχικές δεξιότητες χρηστών
--  Αρχείο: 2026-09-23c_task_skills_seed.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Ο 4a_user_task_skills είναι άδειος. Χωρίς γραμμές, το φίλτρο της
--  ουράς δεν επιστρέφει τίποτα σε κανέναν manager και το σύστημα
--  δουλεύει σωστά δείχνοντας κενή οθόνη.
--
--  Ο ΚΑΝΟΝΑΣ (απόφαση 23/09):
--    GR        κάθε ΕΝΕΡΓΟΣ manager με pricelist_scope GR ή BOTH παίρνει
--              open_code, cms_rates, cms_cod, cms_fuel, notify_client, cod_form
--              χώρα: GR αν scope=GR, BOTH αν scope=BOTH
--    training  ΜΟΝΟ Βίβη Σακκά και Εύα Χιονάτου, χώρα GR
--    CY        ΚΑΜΙΑ γραμμή — δεν έχει αποφασιστεί ποιος αναλαμβάνει
--    admins    ΚΑΜΙΑ γραμμή
--    Αντωνία   ΡΗΤΗ ΕΞΑΙΡΕΣΗ: notify_client + cod_form, χώρα GR
--              (βλ. ενότητα παρακάτω)
--
--  ΓΙΑΤΙ ΟΙ ADMINISTRATORS ΔΕΝ ΠΑΙΡΝΟΥΝ ΓΡΑΜΜΗ:
--  Το auth.php:96 τους δίνει ήδη πρόσβαση σε κάθε ενεργό module — αυτό
--  απαντά «μπορεί να ανοίξει τη σελίδα;». Το skills απαντά άλλο ερώτημα:
--  «είναι κατάλληλος για αυτή τη δουλειά;». Αν οι admins ήταν υποψήφιοι
--  για όλα, καμία εργασία δεν θα έμενε ποτέ «χωρίς υποψήφιο» — και
--  ακριβώς αυτό το σήμα χρειάζεται η ουρά διαχειριστή για να λειτουργεί.
--
--  ΓΙΑΤΙ Η ΚΥΠΡΟΣ ΜΕΝΕΙ ΚΕΝΗ — ΣΚΟΠΙΜΟ, ΟΧΙ ΠΑΡΑΛΕΙΨΗ:
--  Δύο ενεργοί managers έχουν scope=CY (id 7, id 12). Καμία γραμμή δεν
--  τους δίνεται. Οι κυπριακές εργασίες θα συσσωρεύονται αδιάθετες στην
--  ουρά μέχρι να αποφασιστεί ποιος τις αναλαμβάνει. Αυτό ΕΙΝΑΙ το
--  ζητούμενο: η ορατή στοίβα είναι το σήμα ότι λείπει απόφαση.
--
--  ΡΗΤΗ ΕΞΑΙΡΕΣΗ ΑΠΟ ΤΟΝ ΚΑΝΟΝΑ «ΜΟΝΟ MANAGERS» — ANTONIA DOULA:
--  Η ANTONIA DOULA (id 8, E1114) είναι ΕΝΕΡΓΗ, scope=GR, αλλά role=staff
--  και όχι manager. Ο κανόνας θα την άφηνε έξω. Παίρνει παρ' όλα αυτά
--  ΔΥΟ δεξιότητες, με απόφαση του ιδιοκτήτη (23/09):
--
--      notify_client  GR
--      cod_form       GR
--
--  ΑΙΤΙΟΛΟΓΙΑ: αυτές τις δύο «τις κάνουν όλοι» — δεν προϋποθέτουν τίποτα
--  πέρα από επικοινωνία με τον πελάτη. Οι υπόλοιπες πέντε ΔΕΝ δίνονται
--  γιατί απαιτούν πρόσβαση στο CMS (cms_rates, cms_cod, cms_fuel) ή
--  δικαίωμα ανοίγματος κωδικού συνεργασίας (open_code), που ο ρόλος
--  staff δεν έχει. Το training μένει στις δύο που ορίστηκαν ρητά.
--
--  Η εξαίρεση είναι ΡΗΤΗ και περιορισμένη — δεν διευρύνει τον κανόνα σε
--  όλο το staff. Κάθε άλλος staff θέλει τη δική του απόφαση.
--
--  Ο ΚΛΑΔΟΣ 'BOTH' ΔΕΝ ΠΑΡΑΓΕΙ ΓΡΑΜΜΕΣ ΣΗΜΕΡΑ:
--  Κανένας manager δεν έχει pricelist_scope=BOTH — μόνο οι δύο admins,
--  που εξαιρούνται. Ο κανόνας τον περιγράφει για το μέλλον.
--
--  ΤΑ ID ΕΙΝΑΙ ΡΗΤΑ, ΟΧΙ INSERT..SELECT:
--  Ένα δυναμικό INSERT..SELECT FROM 4a_users θα έδινε σιωπηλά δεξιότητες
--  σε όποιον αλλάξει ρόλο στο μέλλον. Οι δεξιότητες είναι απόφαση
--  ανθρώπου, όχι παρενέργεια ρόλου. Το μπλοκ ελέγχου στο τέλος
--  εντοπίζει απόκλιση από τον κανόνα, χωρίς να τη διορθώνει μόνο του.
--
--  ΑΣΦΑΛΕΣ: μόνο INSERT IGNORE σε άδειο πίνακα. Καμία ALTER, κανένα
--  DELETE. Το UNIQUE(user_id, task_code, country) κάνει την επανεκτέλεση
--  ακίνδυνη. granted_by = NULL δηλώνει «από μετάπτωση, όχι από χρήστη».
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Οι δικαιούχοι, όπως ήταν στις 23/09/2026
--
--   id  user_code  όνομα                role     scope   παίρνει
--   --  ---------  -------------------  -------  ------  ---------------
--    3  E1003      VIVI SAKKA           manager  GR      τα 6 + training
--    4  E1079      Eva Chionatou        manager  GR      τα 6 + training
--   13  E1065      MPOUTSIOS STAVROS    manager  GR      τα 6
--    8  E1114      ANTONIA DOULA        staff    GR      2 (ρητή εξαίρεση)
--
--   εξαιρούνται: 1, 2 (administrators) · 7, 12 (scope=CY)
-- ---------------------------------------------------------------------

-- 1. Οι έξι κοινές δεξιότητες για τους τρεις GR managers (18 γραμμές)
INSERT IGNORE INTO `4a_user_task_skills` (`user_id`, `task_code`, `country`, `granted_by`) VALUES
  ( 3, 'open_code',     'GR', NULL),
  ( 3, 'cms_rates',     'GR', NULL),
  ( 3, 'cms_cod',       'GR', NULL),
  ( 3, 'cms_fuel',      'GR', NULL),
  ( 3, 'notify_client', 'GR', NULL),
  ( 3, 'cod_form',      'GR', NULL),

  ( 4, 'open_code',     'GR', NULL),
  ( 4, 'cms_rates',     'GR', NULL),
  ( 4, 'cms_cod',       'GR', NULL),
  ( 4, 'cms_fuel',      'GR', NULL),
  ( 4, 'notify_client', 'GR', NULL),
  ( 4, 'cod_form',      'GR', NULL),

  (13, 'open_code',     'GR', NULL),
  (13, 'cms_rates',     'GR', NULL),
  (13, 'cms_cod',       'GR', NULL),
  (13, 'cms_fuel',      'GR', NULL),
  (13, 'notify_client', 'GR', NULL),
  (13, 'cod_form',      'GR', NULL);

-- 2. Εκμάθηση πλατφόρμας — ΜΟΝΟ Βίβη και Εύα (2 γραμμές)
--    Ο Σταύρος (13) ΔΕΝ την παίρνει, σκόπιμα.
INSERT IGNORE INTO `4a_user_task_skills` (`user_id`, `task_code`, `country`, `granted_by`) VALUES
  ( 3, 'training', 'GR', NULL),
  ( 4, 'training', 'GR', NULL);

-- 3. ANTONIA DOULA (id 8) — ΡΗΤΗ ΕΞΑΙΡΕΣΗ από τον κανόνα «μόνο managers»
--    role=staff. Μόνο οι δύο εργασίες που «τις κάνουν όλοι»: δεν απαιτούν
--    πρόσβαση CMS ούτε δικαίωμα ανοίγματος κωδικού. (2 γραμμές)
INSERT IGNORE INTO `4a_user_task_skills` (`user_id`, `task_code`, `country`, `granted_by`) VALUES
  ( 8, 'notify_client', 'GR', NULL),
  ( 8, 'cod_form',      'GR', NULL);

-- ---------------------------------------------------------------------
-- 4. Έλεγχος
-- ---------------------------------------------------------------------

-- Αναμένονται 22 γραμμές: 3 managers x 6 + 2 training + 2 (Αντωνία)
SELECT COUNT(*) AS `ΣΥΝΟΛΟ_ΓΡΑΜΜΩΝ` FROM `4a_user_task_skills`;

SELECT u.`id`, u.`user_code`, u.`name`, u.`role`, u.`pricelist_scope`,
       COUNT(s.`id`) AS `δεξιοτητες`,
       GROUP_CONCAT(s.`task_code` ORDER BY s.`task_code` SEPARATOR ',') AS `ποιες`
  FROM `4a_users` u
  LEFT JOIN `4a_user_task_skills` s ON s.`user_id` = u.`id`
 WHERE u.`active` = 1
 GROUP BY u.`id`, u.`user_code`, u.`name`, u.`role`, u.`pricelist_scope`
 ORDER BY `δεξιοτητες` DESC, u.`id`;

-- Κάθε task_code πρέπει να υπάρχει στο 4a_task_types. Κενό = σωστό.
SELECT s.`task_code` AS `ΑΓΝΩΣΤΟΣ_ΚΩΔΙΚΟΣ`
  FROM `4a_user_task_skills` s
  LEFT JOIN `4a_task_types` t ON t.`code` = s.`task_code`
 WHERE t.`code` IS NULL
 GROUP BY s.`task_code`;

-- ΑΠΟΚΛΙΣΗ ΑΠΟ ΤΟΝ ΚΑΝΟΝΑ: ενεργοί managers GR/BOTH χωρίς καμία γραμμή.
-- Σήμερα πρέπει να είναι κενό. Αν αύριο προστεθεί manager GR και δεν
-- τρέξει νέο seed, θα εμφανιστεί εδώ.
SELECT u.`id`, u.`user_code`, u.`name` AS `MANAGER_GR_ΧΩΡΙΣ_ΔΕΞΙΟΤΗΤΕΣ`
  FROM `4a_users` u
 WHERE u.`active` = 1
   AND u.`role` = 'manager'
   AND u.`pricelist_scope` IN ('GR','BOTH')
   AND NOT EXISTS (SELECT 1 FROM `4a_user_task_skills` s WHERE s.`user_id` = u.`id`);

-- Υπενθύμιση: η Κύπρος πρέπει να δείχνει 0. Είναι σκόπιμο.
SELECT COUNT(*) AS `ΓΡΑΜΜΕΣ_CY` FROM `4a_user_task_skills` WHERE `country` = 'CY';
