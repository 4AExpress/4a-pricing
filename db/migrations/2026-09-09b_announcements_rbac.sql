-- =====================================================================
--  4a-pricing · Ανακοινώσεις — RBAC
--  Αρχείο: 2026-09-09b_announcements_rbac.sql
--  ΤΡΕΧΕΙ ΜΟΝΟ ΜΕΤΑ το 2026-09-09_announcements.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Ευθυγράμμιση τύπων με το 4a_users.id (int SIGNED)
--    Ασφαλές: οι πίνακες μόλις δημιουργήθηκαν, το reads είναι άδειο.
-- ---------------------------------------------------------------------
ALTER TABLE `4a_announcement_reads` MODIFY `user_id`    INT NOT NULL;
ALTER TABLE `4a_announcements`      MODIFY `created_by` INT DEFAULT NULL;


-- ---------------------------------------------------------------------
-- 2. Modules — ΔΥΟ, και κανένα με το σκέτο όνομα `announcements`
--
--    ΓΙΑΤΙ ΔΕΝ ΥΠΑΡΧΕΙ module για την ΑΝΑΓΝΩΣΗ ανακοινώσεων:
--    το user_permissions ΑΝΤΙΚΑΘΙΣΤΑ τον ρόλο (auth.php:110-118).
--    Μία γραμμή υπερκάλυψης σε λάθος module αφαιρεί σιωπηλά την
--    πρόσβαση — ακριβώς ό,τι έπαθε ο χρήστης 7 με το `offers`.
--    Οι ανακοινώσεις είναι ΥΠΟΧΡΕΩΣΗ, όχι δικαίωμα· κανείς δεν
--    πρέπει να μπορεί να τις χάσει από κενή γραμμή σε πίνακα.
--    Το endpoint ανάγνωσης χρησιμοποιεί require_user() σκέτο.
-- ---------------------------------------------------------------------
INSERT INTO `modules` (`id`,`label`,`icon`,`sort_order`,`active`) VALUES
  ('announcements-admin',  'Σύνταξη ανακοινώσεων', 'ti-speakerphone', 55, 1),
  ('announcements-report', 'Αναφορά ανάγνωσης',    'ti-clock',        56, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- Οι δύο administrators τα παίρνουν ΑΥΤΟΜΑΤΑ (auth.php:96-102 δίνει
-- πλήρη δικαιώματα σε κάθε ενεργό module). Δεν χρειάζεται γραμμή.


-- ---------------------------------------------------------------------
-- 3. Ρόλοι: όλοι οι μη-administrators παίρνουν ΜΗΔΕΝ και στα δύο.
--    Ρητές γραμμές, ώστε το «όχι» να είναι καταγεγραμμένη απόφαση
--    και όχι απουσία εγγραφής.
--
--    ΠΡΑΓΜΑΤΙΚΟΙ ρόλοι: 1 administrator, 2 manager, 3 staff,
--                       4 readonly, 5 trainee
--    ΔΕΝ υπάρχει `admin` ούτε `superadmin`.
-- ---------------------------------------------------------------------
INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES
  (2,'announcements-admin',0,0,0,0,0),
  (3,'announcements-admin',0,0,0,0,0),
  (4,'announcements-admin',0,0,0,0,0),
  (5,'announcements-admin',0,0,0,0,0),
  (2,'announcements-report',0,0,0,0,0),
  (3,'announcements-report',0,0,0,0,0),
  (4,'announcements-report',0,0,0,0,0),
  (5,'announcements-report',0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  `can_view`=VALUES(`can_view`), `can_add`=VALUES(`can_add`),
  `can_edit`=VALUES(`can_edit`), `can_delete`=VALUES(`can_delete`),
  `can_export`=VALUES(`can_export`);

-- Κανείς δεν έχει can_delete. Ανακοίνωση δεν σβήνεται —
-- απενεργοποιείται (active=0). Οι βεβαιώσεις παραλαβής κρέμονται
-- από αυτήν και είναι αποδεικτικά ενημέρωσης.


-- ---------------------------------------------------------------------
-- 4. ΠΑΡΑΔΕΙΓΜΑ — δικαίωμα αναφοράς σε συγκεκριμένο υπεύθυνο.
--    ΜΗΝ το τρέξεις χωρίς να αντικαταστήσεις το 999.
-- ---------------------------------------------------------------------
-- INSERT INTO `user_permissions`
--   (`user_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
-- VALUES (999,'announcements-report',1,0,0,0,0)
-- ON DUPLICATE KEY UPDATE `can_view`=1;


-- ---------------------------------------------------------------------
-- 5. Έλεγχος
-- ---------------------------------------------------------------------
SELECT r.`name`, rp.`module_id`, rp.`can_view`, rp.`can_export`
  FROM `role_permissions` rp
  JOIN `roles` r ON r.`id` = rp.`role_id`
 WHERE rp.`module_id` LIKE 'announcements%'
 ORDER BY rp.`module_id`, r.`id`;

SHOW COLUMNS FROM `4a_announcement_reads` LIKE 'user_id';
