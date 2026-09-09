-- =====================================================================
--  4a-pricing · Σύστημα ανακοινώσεων
--  Αρχείο: 2026-09-09_announcements.sql
--  DB: dbdkhuhge5dmn2 (SiteGround, MySQL 8)
--
--  ΑΣΦΑΛΕΣ: μόνο CREATE TABLE + ένα INSERT με active=0.
--  Δεν αγγίζει κανέναν υπάρχοντα πίνακα. Δεν κάνει DROP τίποτα.
--
--  ΔΕΝ περιλαμβάνει τη γραμμή στο RBAC (modules/role_permissions) —
--  αυτή γράφεται αφού δούμε το πραγματικό σχήμα των δύο πινάκων.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Ανακοινώσεις
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_announcements` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Ταυτότητα
  `code`           VARCHAR(40)  NOT NULL COMMENT 'REL-2026-09-09-OFFERS',
  `revision`       SMALLINT UNSIGNED NOT NULL DEFAULT 1
                   COMMENT '+1 => ξαναεμφανίζεται σε όσους την είχαν δει',

  -- Περιεχόμενο. Τρία ΞΕΧΩΡΙΣΤΑ πεδία, όχι ένα ελεύθερο κείμενο:
  -- το σχήμα επιβάλλει τη δομή, ώστε να μη γραφτεί ποτέ
  -- «έγινε update στο pricelist module» και τίποτα άλλο.
  `title`          VARCHAR(160) NOT NULL,
  `summary`        VARCHAR(255) NOT NULL COMMENT 'μία γραμμή για τη λίστα',
  `body_changed`   TEXT NOT NULL         COMMENT 'Τι άλλαξε',
  `body_why`       TEXT NOT NULL         COMMENT 'Γιατί σε αφορά',
  `body_todo`      TEXT NOT NULL         COMMENT 'Τι κάνεις αλλιώς',

  -- Εμφάνιση
  `icon`           VARCHAR(16)  NOT NULL DEFAULT '✨',
  `category`       ENUM('feature','change','fix','notice') NOT NULL DEFAULT 'feature',
  `severity`       ENUM('info','important','critical')     NOT NULL DEFAULT 'info',

  -- Κοινό
  `audience_roles` VARCHAR(255) DEFAULT NULL
                   COMMENT 'csv ρόλων· NULL = όλοι',
  `audience_scope` ENUM('GR','CY','BOTH') NOT NULL DEFAULT 'BOTH',

  -- Ενέργειες
  `cta_label`      VARCHAR(64)  DEFAULT NULL,
  `cta_url`        VARCHAR(255) DEFAULT NULL,
  `require_ack`    TINYINT(1)   NOT NULL DEFAULT 0
                   COMMENT '1 = πρέπει να βεβαιώσει παραλαβή για να συνεχίσει',

  -- GDPR: η καταγραφή χρόνου είναι ΡΗΤΗ επιλογή ανά ανακοίνωση,
  -- όχι σιωπηρή συνέπεια του severity. Ελέγξιμη, απενεργοποιήσιμη.
  `track_reading`  TINYINT(1)   NOT NULL DEFAULT 0
                   COMMENT '1 = καταγράφεται ενεργός χρόνος ανάγνωσης',

  -- Δημοσίευση
  `active`         TINYINT(1)   NOT NULL DEFAULT 0,
  `published_at`   DATETIME     DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code` (`code`),
  KEY `idx_live` (`active`,`published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- 2. Παραλαβές / ανάγνωση
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `4a_announcement_reads` (
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,

  `revision_seen`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `first_seen_at`   DATETIME     DEFAULT NULL,
  `last_seen_at`    DATETIME     DEFAULT NULL,
  `acked_at`        DATETIME     DEFAULT NULL
                    COMMENT 'βεβαίωση παραλαβής — ΔΕΝ διαγράφεται, είναι απόδειξη',

  -- Τηλεμετρία ανάγνωσης. ΔΙΑΓΡΑΦΕΤΑΙ μετά από 12 μήνες (βλ. §4).
  `opens`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `dwell_sec`       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0
                    COMMENT 'ΕΝΕΡΓΑ δευτερόλεπτα: ανοιχτό κείμενο + ενεργή καρτέλα',
  `purged_at`       DATETIME     DEFAULT NULL,

  PRIMARY KEY (`announcement_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_purge` (`purged_at`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Σημείωση: επίτηδες ΧΩΡΙΣ FOREIGN KEY προς `4a_users`.
-- Δεν έχω επιβεβαιώσει τον τύπο του PK εκεί· ένα λάθος FK ρίχνει
-- ολόκληρο το migration. Μπαίνει αργότερα, αφού δούμε το DESCRIBE.


-- ---------------------------------------------------------------------
-- 3. Πρώτη ανακοίνωση — ΑΝΕΝΕΡΓΗ (active=0)
--    Δεν τη βλέπει κανείς μέχρι να συμφωνήσουμε στο κείμενο.
-- ---------------------------------------------------------------------
INSERT INTO `4a_announcements`
  (`code`,`revision`,`title`,`summary`,
   `body_changed`,`body_why`,`body_todo`,
   `icon`,`category`,`severity`,`audience_roles`,`audience_scope`,
   `cta_label`,`cta_url`,`require_ack`,`track_reading`,`active`,`published_at`)
VALUES
  ('REL-2026-09-09-OFFERS', 1,
   'Οι προσφορές κλειδώνουν όταν σταλούν',
   'Κάθε σταλμένη προσφορά κρατιέται όπως ακριβώς την είδε ο πελάτης.',
   'Μόλις στείλεις μια προσφορά, το σύστημα κρατάει μια φωτογραφία της — τιμές, υπηρεσίες, έγγραφα, όλα. Παίρνει και δικό της αριθμό.',
   'Αν αύριο αλλάξει ο τιμοκατάλογος ή το καύσιμο, η σταλμένη προσφορά <b>δεν αλλάζει</b>. Όταν ο πελάτης πει «εσείς μου γράψατε 4,20», ανοίγεις το αρχείο και έχεις το ίδιο ακριβώς PDF.',
   'Σταλμένη προσφορά <b>δεν διορθώνεται</b>. Αν χρειάζεται αλλαγή, εκδίδεις νέα — η παλιά μένει στο ιστορικό. Κρατάμε επίσης σε ποιον στάλθηκε, πότε και από ποιον.',
   '📦','feature','critical', NULL, 'BOTH',
   'Άνοιξε το αρχείο προσφορών', 'pricelist-clients.html#offers',
   1, 1, 0, NULL)
ON DUPLICATE KEY UPDATE `code` = `code`;   -- idempotent: ξανατρέξιμο δεν διπλογράφει


-- ---------------------------------------------------------------------
-- 4. Διαγραφή τηλεμετρίας μετά από 12 μήνες
--    ΜΗΝ το ενεργοποιήσεις πριν βεβαιωθείς ότι το event_scheduler
--    είναι διαθέσιμο στο shared hosting. Αλλιώς: cron + ένα μικρό PHP.
-- ---------------------------------------------------------------------
-- SET GLOBAL event_scheduler = ON;   -- συνήθως ΔΕΝ επιτρέπεται στη SiteGround

-- Το ισοδύναμο ερώτημα, για cron μία φορά τον μήνα:
--
--   UPDATE `4a_announcement_reads`
--      SET `opens` = 0, `dwell_sec` = 0, `purged_at` = NOW()
--    WHERE `purged_at` IS NULL
--      AND `last_seen_at` < DATE_SUB(NOW(), INTERVAL 12 MONTH);
--
-- Το `acked_at` παραμένει: είναι απόδειξη ενημέρωσης και έχει
-- αυτοτελή λόγο διατήρησης. Ο χρόνος ανάγνωσης δεν έχει.


-- ---------------------------------------------------------------------
-- 5. Έλεγχος
-- ---------------------------------------------------------------------
SELECT `id`,`code`,`severity`,`require_ack`,`track_reading`,`active`
  FROM `4a_announcements`;

SELECT COUNT(*) AS reads_rows FROM `4a_announcement_reads`;
