-- ΕΤΡΕΞΕ ΚΑΤΑ ΛΑΘΟΣ 09/09/2026 17:23. Δημιούργησε module
-- `announcements` που δεν έπρεπε να υπάρχει.
-- Διορθώθηκε από το 2026-09-09c_fix_announcements_module.sql
-- Διατηρείται ως ιστορικό. ΜΗΝ ΤΟ ΞΑΝΑΤΡΕΞΕΙΣ.
-- =====================================================================
--  4a-pricing · Ανακοινώσεις — συμπλήρωμα μετά το discovery
--  Αρχείο: 2026-09-09b_announcements_rbac.sql
--
--  ΤΡΕΧΕΙ ΜΟΝΟ ΜΕΤΑ το 2026-09-09_announcements.sql
--
--  Τι κάνει:
--   1. Διορθώνει ασυμφωνία τύπων με το 4a_users.id (int SIGNED)
--   2. Προσθέτει δύο modules στο RBAC
--   3. Δίνει δικαιώματα ανά ρόλο, με τα ΠΡΑΓΜΑΤΙΚΑ ονόματα ρόλων
--
--  ΔΕΝ αγγίζει: 4a_users, modules (υπάρχουσες γραμμές), roles
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Ευθυγράμμιση τύπων
--    Το 4a_users.id είναι `int` SIGNED. Είχα δηλώσει UNSIGNED.
--    Ασφαλές τώρα: οι πίνακες μόλις δημιουργήθηκαν και είναι άδειοι
--    (εκτός από μία γραμμή στο 4a_announcements με created_by = NULL).
-- ---------------------------------------------------------------------
ALTER TABLE `4a_announcement_reads`
  MODIFY `user_id` INT NOT NULL;

ALTER TABLE `4a_announcements`
  MODIFY `created_by` INT DEFAULT NULL;

-- Τα FOREIGN KEYS μπαίνουν σε ξεχωριστό βήμα, αφού επιβεβαιωθεί
-- ότι το 4a_users είναι InnoDB και ότι δεν υπάρχουν ορφανά id.


-- ---------------------------------------------------------------------
-- 2. Modules
--    ΔΥΟ, όχι ένα. Η αναφορά ανάγνωσης είναι τηλεμετρία εργαζομένων:
--    πρέπει να δίνεται και να ανακαλείται ανεξάρτητα από το δικαίωμα
--    ανάγνωσης των ίδιων των ανακοινώσεων.
-- ---------------------------------------------------------------------
INSERT INTO `modules` (`id`,`label`,`icon`,`sort_order`,`active`) VALUES
  ('announcements',        'Ανακοινώσεις',      'ti-speakerphone', 55, 1),
  ('announcements-report', 'Αναφορά ανάγνωσης', 'ti-clock',        56, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);


-- ---------------------------------------------------------------------
-- 3. Δικαιώματα ανά ρόλο
--
--    ΠΡΑΓΜΑΤΙΚΟΙ ρόλοι (από τον πίνακα `roles`):
--      1 administrator   4 readonly
--      2 manager         5 trainee
--      3 staff
--
--    ΠΡΟΣΟΧΗ: δεν υπάρχει `admin` ούτε `superadmin`.
--    Ό,τι γράφεται στο audience_roles ΠΡΕΠΕΙ να χρησιμοποιεί
--    τα παραπάνω ονόματα, αλλιώς η ανακοίνωση δεν εμφανίζεται
--    πουθενά — σιωπηλά, χωρίς σφάλμα.
-- ---------------------------------------------------------------------

-- 3α. Ανακοινώσεις: όλοι διαβάζουν, μόνο ο administrator γράφει.
INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES
  (1,'announcements',1,1,1,0,0),   -- administrator: γράφει & επεξεργάζεται
  (2,'announcements',1,0,0,0,0),   -- manager
  (3,'announcements',1,0,0,0,0),   -- staff
  (4,'announcements',1,0,0,0,0),   -- readonly
  (5,'announcements',1,0,0,0,0)    -- trainee
ON DUPLICATE KEY UPDATE
  `can_view`=VALUES(`can_view`), `can_add`=VALUES(`can_add`),
  `can_edit`=VALUES(`can_edit`), `can_delete`=VALUES(`can_delete`),
  `can_export`=VALUES(`can_export`);

-- Σκόπιμα ΚΑΝΕΙΣ δεν έχει can_delete.
-- Μια ανακοίνωση δεν σβήνεται: απενεργοποιείται (active=0).
-- Οι βεβαιώσεις παραλαβής που κρέμονται από αυτήν είναι αποδεικτικά.

-- 3β. Αναφορά ανάγνωσης: μόνο administrator σε επίπεδο ρόλου.
--     Οι υπεύθυνοι ΔΕΝ την παίρνουν συλλογικά — δίνεται ανά άτομο.
INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES
  (1,'announcements-report',1,0,0,0,1),
  (2,'announcements-report',0,0,0,0,0),
  (3,'announcements-report',0,0,0,0,0),
  (4,'announcements-report',0,0,0,0,0),
  (5,'announcements-report',0,0,0,0,0)
ON DUPLICATE KEY UPDATE
  `can_view`=VALUES(`can_view`), `can_export`=VALUES(`can_export`);


-- ---------------------------------------------------------------------
-- 4. Έλεγχος
-- ---------------------------------------------------------------------
SELECT r.`name`, rp.`module_id`, rp.`can_view`, rp.`can_add`,
       rp.`can_edit`, rp.`can_delete`, rp.`can_export`
  FROM `role_permissions` rp
  JOIN `roles` r ON r.`id` = rp.`role_id`
 WHERE rp.`module_id` LIKE 'announcements%'
 ORDER BY rp.`module_id`, r.`id`;

SHOW COLUMNS FROM `4a_announcement_reads` LIKE 'user_id';


-- =====================================================================
--  ΕΚΚΡΕΜΕΙ — δεν γράφεται πριν απαντηθούν:
--
--  α) Υπάρχει πίνακας υπερκάλυψης δικαιωμάτων ΑΝΑ ΧΡΗΣΤΗ;
--     Αν ναι, εκεί μπαίνει το announcements-report του κάθε υπευθύνου.
--     Αν όχι, χρειάζεται νέος πίνακας — και αυτό είναι σχεδιαστική
--     απόφαση που αφορά ΟΛΟ το RBAC, όχι μόνο τις ανακοινώσεις.
--         SHOW TABLES;
--
--  β) Τι τιμές έχει πραγματικά το `4a_users`.`role`;
--     Το default είναι 'user' — τιμή που ΔΕΝ υπάρχει στον `roles`.
--         SELECT `role`, COUNT(*) FROM `4a_users` GROUP BY `role`;
-- =====================================================================
