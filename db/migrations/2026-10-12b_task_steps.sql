-- =====================================================================
--  2026-10-12b_task_steps.sql
--
--  ΦΑΣΗ 5, Κ1α. Βήματα εργασίας ανά τύπο, στη βάση.
--
--  4a_task_steps   ο ΔΙΑΚΟΠΤΗΣ της νέας κάρτας. Τύπος ΧΩΡΙΣ γραμμές εδώ
--                  = ακριβώς η σημερινή συμπεριφορά (γενικό «Ολοκλήρωση»,
--                  γενική κάρτα). Τύπος ΜΕ γραμμές = κάρτα βημάτων, και ο
--                  server αρνείται (409) το γενικό done.
--
--    kind = 'step'   ένα βήμα της ροής, με τη σειρά του sort_order:
--      step_code       σταθερό όνομα (download, upload, uploaded)
--      action          τι κάνει το κουμπί: download | upload | NULL (κανένα)
--      done_event      το γεγονός του 4a_task_events που ΚΛΕΙΝΕΙ το βήμα
--      is_final        1 = η κατάσταση μετά το κλείσιμο της εργασίας
--      status_label    ετικέτα «ΚΑΤΑΣΤΑΣΗ» (δέχεται {week_start})
--      hint            οδηγία «ΤΙ ΠΡΕΠΕΙ ΝΑ ΚΑΝΕΙΣ» (δέχεται {week_start})
--      button_label    κείμενο κουμπιού, button_icon εικονίδιο
--      timeline_label  η γραμμή «✓ …» στο χρονολόγιο όταν κλείσει
--
--    kind = 'event'  ετικέτα χρονολογίου για γεγονός που ΔΕΝ είναι βήμα
--                    (admin_override, upload_failed, file_viewed, done):
--      step_code       το όνομα του γεγονότος
--      timeline_label  το κείμενο της γραμμής
--      alert           1 = κόκκινη σήμανση (admin_override)
--
--    kind = 'ui'     κείμενο της ΚΑΡΤΑΣ (τίτλοι στηλών, μενού, παράθυρα
--                    ανεβάσματος και παράκαμψης), απόφαση 12/10:
--      step_code       το κλειδί (π.χ. h_do, up_title)
--      text            το κείμενο, με {n} / {rows} / {name} / {at} όπου χρειάζεται
--                    Τα ΓΕΝΙΚΑ κουμπιά της σελίδας (Αφήνω, Απόρριψη…) ΔΕΝ
--                    μπαίνουν εδώ: μένουν στον κώδικα (backlog).
--
--  ΧΩΡΙΣ SEED. Ο πίνακας μένει ΑΔΕΙΟΣ, άρα τίποτα δεν αλλάζει στη σημερινή
--  εμπειρία. Τα κείμενα μπαίνουν με το migration ενεργοποίησης (Κ4α), μετά
--  από έγκριση του πίνακα κειμένων. Διορθώσεις κειμένων ΜΟΝΟ με migration.
--
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αγγίζεται.
--
--  Τρέχει ΠΡΙΝ από το SCP του api/tasks_lib.php που τον διαβάζει. Ο κώδικας
--  αντέχει και την απουσία του (καμία γραμμή = σημερινή συμπεριφορά).
--
--  Αντίστροφο: rollback/2026-10-12b_task_steps.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_task_steps` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `task_code`      VARCHAR(40)   NOT NULL COMMENT '4a_task_types.code',
  `kind`           ENUM('step','event','ui') NOT NULL DEFAULT 'step',
  `step_code`      VARCHAR(30)   NOT NULL COMMENT 'βήμα, ή όνομα γεγονότος για kind=event',
  `sort_order`     INT           NOT NULL DEFAULT 0,
  `action`         VARCHAR(20)       NULL DEFAULT NULL COMMENT 'download | upload | NULL',
  `done_event`     VARCHAR(30)       NULL DEFAULT NULL COMMENT 'γεγονός που κλείνει το βήμα',
  `is_final`       TINYINT(1)    NOT NULL DEFAULT 0,
  `status_label`   VARCHAR(160)      NULL DEFAULT NULL,
  `hint`           VARCHAR(400)      NULL DEFAULT NULL,
  `button_label`   VARCHAR(80)       NULL DEFAULT NULL,
  `button_icon`    VARCHAR(16)       NULL DEFAULT NULL,
  `timeline_label` VARCHAR(160)      NULL DEFAULT NULL,
  `alert`          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = κόκκινη γραμμή στο χρονολόγιο',
  `text`           VARCHAR(400)      NULL DEFAULT NULL COMMENT 'kind=ui: κείμενο της κάρτας',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_task_kind_step` (`task_code`, `kind`, `step_code`),
  CONSTRAINT `fk_task_steps_type` FOREIGN KEY (`task_code`) REFERENCES `4a_task_types` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
SELECT `TABLE_NAME`, `TABLE_COLLATION`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = '4a_task_steps';

-- Αναμενόμενο: 0 γραμμές (κανένας τύπος ενεργοποιημένος).
SELECT COUNT(*) AS `γραμμές` FROM `4a_task_steps`;
