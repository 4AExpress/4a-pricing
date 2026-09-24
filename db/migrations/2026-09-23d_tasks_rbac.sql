-- =====================================================================
--  4a-pricing · Εργασίες: module RBAC
--  Αρχείο: 2026-09-23d_tasks_rbac.sql
--  Προϋπόθεση: 2026-09-23_tasks_foundation.sql
--
--  ΓΙΑΤΙ:
--  Η οθόνη εργασιών (Φάση 3) χρειάζεται module για να ελεγχθεί με
--  require_permission('tasks', ...). Χωρίς γραμμή στον `modules`, το
--  auth.php δεν ξέρει καν ότι υπάρχει.
--
--  ΠΟΙΑ ACTIONS:
--    view  — βλέπει την οθόνη, τις δικές του και την ουρά
--    edit  — αναλαμβάνει, αφήνει, ολοκληρώνει, σημαίνει na
--    add    ΔΕΝ χρησιμοποιείται: τις εργασίες τις δημιουργεί ο κώδικας
--           της Φάσης 2 (tasks_create.php), ποτέ ο χρήστης.
--    delete ΣΕ ΚΑΝΕΝΑΝ: εργασία δεν σβήνεται, σημαίνεται 'na' με λόγο.
--           Ίδιος κανόνας με τις ανακοινώσεις.
--    export ΟΧΙ στη Φάση 3.
--
--  ΡΗΤΕΣ ΓΡΑΜΜΕΣ ΚΑΙ ΓΙΑ ΤΟ «ΟΧΙ»:
--  Το trainee παίρνει μηδενικά, γραμμένα. Το ίδιο μοτίβο με το
--  2026-09-09b_announcements_rbac.sql:44-58 — το «όχι» πρέπει να είναι
--  καταγεγραμμένη απόφαση και όχι απουσία εγγραφής.
--
--  ΟΙ ADMINISTRATORS ΔΕΝ ΠΑΙΡΝΟΥΝ ΓΡΑΜΜΗ:
--  Το api/auth.php:96-102 τους δίνει πλήρη δικαιώματα σε ΚΑΘΕ ενεργό
--  module, διαβάζοντας απευθείας τον `modules`. Αρκεί το active = 1.
--
--  ΠΡΟΣΟΧΗ ΣΤΟ user_permissions:
--  Για κάθε μη-administrator, μια γραμμή εκεί ΑΝΤΙΚΑΘΙΣΤΑ ολόκληρο το
--  module (auth.php:104-119) — δεν συγχωνεύεται με τον ρόλο. Αυτό είχε
--  κλειδώσει έξω τον χρήστη 7 από το `offers`. Αυτό το migration ΔΕΝ
--  γράφει τίποτα στο user_permissions.
--
--  ΑΣΦΑΛΕΣ: μόνο INSERT ... ON DUPLICATE KEY UPDATE σε δύο πίνακες
--  μεταδεδομένων. Καμία ALTER, κανένα DELETE. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Το module
--    sort_order 22: ανάμεσα σε pricelist-clients (20) και offers (25),
--    εκεί που ανήκει ροϊκά — ο πελάτης γίνεται accepted, ακολουθούν
--    οι εργασίες.
-- ---------------------------------------------------------------------
INSERT INTO `modules` (`id`, `label`, `icon`, `sort_order`, `active`) VALUES
  ('tasks', 'Εργασίες', 'ti-checklist', 22, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);

-- ---------------------------------------------------------------------
-- 2. Δικαιώματα ανά ρόλο
--    ΠΡΑΓΜΑΤΙΚΟΙ ρόλοι (έλεγχος 23/09): 1 administrator, 2 manager,
--    3 staff, 4 readonly, 5 trainee. ΔΕΝ υπάρχει `admin`.
--
--    staff παίρνει edit όπως ο manager: η Αντωνία (id 8, staff) έχει
--    ήδη δύο δεξιότητες στο 4a_user_task_skills και πρέπει να μπορεί να
--    τις αναλάβει. Ποιος βλέπει ΤΙ το κρίνουν οι δεξιότητες, όχι ο ρόλος.
-- ---------------------------------------------------------------------
INSERT INTO `role_permissions`
  (`role_id`, `module_id`, `can_view`, `can_add`, `can_edit`, `can_delete`, `can_export`)
VALUES
  (2, 'tasks', 1, 0, 1, 0, 0),   -- manager
  (3, 'tasks', 1, 0, 1, 0, 0),   -- staff
  (4, 'tasks', 1, 0, 0, 0, 0),   -- readonly: βλέπει, δεν αγγίζει
  (5, 'tasks', 0, 0, 0, 0, 0)    -- trainee: τίποτα, ρητά
ON DUPLICATE KEY UPDATE
  `can_view`   = VALUES(`can_view`),
  `can_add`    = VALUES(`can_add`),
  `can_edit`   = VALUES(`can_edit`),
  `can_delete` = VALUES(`can_delete`),
  `can_export` = VALUES(`can_export`);

-- ---------------------------------------------------------------------
-- 3. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `id`, `label`, `icon`, `sort_order`, `active`
  FROM `modules` WHERE `id` = 'tasks';

SELECT r.`id`, r.`name`, rp.`can_view`, rp.`can_add`, rp.`can_edit`,
       rp.`can_delete`, rp.`can_export`
  FROM `roles` r
  LEFT JOIN `role_permissions` rp ON rp.`role_id` = r.`id` AND rp.`module_id` = 'tasks'
 ORDER BY r.`id`;

-- Κανείς δεν πρέπει να έχει can_delete. Κενό = σωστό.
SELECT `role_id` AS `ΕΧΕΙ_DELETE`
  FROM `role_permissions` WHERE `module_id` = 'tasks' AND `can_delete` = 1;

-- Το migration ΔΕΝ γράφει user_permissions. Ό,τι φανεί εδώ προϋπήρχε.
SELECT `user_id`, `can_view`, `can_edit`
  FROM `user_permissions` WHERE `module_id` = 'tasks';
