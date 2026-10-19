-- =====================================================================
--  2026-10-23a_fuel_verify.sql
--
--  ΕΠΑΛΗΘΕΥΤΗΣ του fuel_weekly_verify (σχέδιο verifier_plan.md, 19/10).
--  Ο επαληθευτής ανοίγει στο CMS κάθε γραμμή ελέγχου και διαλέγει, ανάμεσα
--  σε 4 τιμές, αυτή που δείχνει το CMS. Ο server ξέρει ποια είναι σωστή·
--  ο browser ΠΟΤΕ (εκτός από όποιον έχει το δικαίωμα fuel_verify_values,
--  migration 2026-10-23b).
--
--  4a_fuel_verify_checks    οι γραμμές ελέγχου: πελάτης, σταθμός, υπηρεσία,
--                           ζώνη, τύπος καυσίμου. Η αναμενόμενη τιμή =
--                           payload.prices[fuel_type] της εργασίας verify.
--                           Τύπος εργασίας με ενεργές γραμμές = ο διακόπτης.
--  4a_fuel_verify_settings  οι κανόνες της κλήρωσης και το from_week.
--  4a_fuel_verify_options   οι 4 τιμές ανά εργασία, γύρο και γραμμή:
--                           κληρώνονται ΜΙΑ φορά (αλλιώς δύο ανανεώσεις θα
--                           πρόδιδαν τη σωστή ως τη μόνη σταθερή).
--  4a_fuel_verify_answers   κάθε απάντηση: ποιος, πότε, γραμμή, τιμή,
--                           σωστή/λάθος. Μία υποβολή ανά γύρο (μετά από
--                           λάθος κλειδώνει, ως την επαναφορά από admin).
--
--  from_week: verify με week_start ΠΡΙΝ από αυτό κρατά την παλιά ροή (done
--  με Δ-Α8). Φρένο χωρίς κώδικα: UPDATE σε μεταγενέστερη Δευτέρα.
--
--  ΚΑΜΙΑ εργασία (4a_tasks) δεν αγγίζεται.
--
--  Τρέχει ΠΡΙΝ από το SCP του api/fuel_verify_lib.php. Ο κώδικας αντέχει
--  και την απουσία του (ο επαληθευτής μένει ανενεργός).
--
--  Αντίστροφο: rollback/2026-10-23a_fuel_verify.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_fuel_verify_checks` (
  `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `task_code`       VARCHAR(40)      NOT NULL COMMENT '4a_task_types.code του verify',
  `client_code`     VARCHAR(16)      NOT NULL COMMENT 'κωδικός πελάτη στο CMS',
  `station_country` ENUM('GR','CY')  NOT NULL,
  `cms_service`     VARCHAR(16)      NOT NULL,
  `zone`            VARCHAR(8)       NOT NULL,
  `fuel_type`       VARCHAR(16)      NOT NULL COMMENT '4a_fuel_types.code',
  `sort_order`      INT              NOT NULL DEFAULT 0,
  `active`          TINYINT(1)       NOT NULL DEFAULT 1,
  `note`            VARCHAR(160)     NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fv_check` (`task_code`, `client_code`, `station_country`, `cms_service`, `zone`),
  CONSTRAINT `fk_fv_check_type` FOREIGN KEY (`task_code`) REFERENCES `4a_task_types` (`code`),
  CONSTRAINT `fk_fv_check_fuel` FOREIGN KEY (`fuel_type`) REFERENCES `4a_fuel_types` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_fuel_verify_settings` (
  `k`    VARCHAR(40)  NOT NULL,
  `v`    VARCHAR(400) NOT NULL,
  `note` VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_fuel_verify_options` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `task_id`         INT UNSIGNED  NOT NULL,
  `round`           INT           NOT NULL COMMENT '1, και +1 σε κάθε επαναφορά',
  `check_id`        INT UNSIGNED  NOT NULL,
  `client_code`     VARCHAR(16)   NOT NULL COMMENT 'στιγμιότυπο της γραμμής ελέγχου',
  `station_country` VARCHAR(4)    NOT NULL,
  `cms_service`     VARCHAR(16)   NOT NULL,
  `zone`            VARCHAR(8)    NOT NULL,
  `fuel_type`       VARCHAR(16)   NOT NULL,
  `sort_order`      INT           NOT NULL DEFAULT 0,
  `options`         VARCHAR(400)  NOT NULL COMMENT 'JSON: οι τιμές ως "49.00"',
  `correct`         DECIMAL(8,2)  NOT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fv_opt` (`task_id`, `round`, `check_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_fuel_verify_answers` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `task_id`     INT UNSIGNED  NOT NULL,
  `round`       INT           NOT NULL,
  `check_id`    INT UNSIGNED  NOT NULL,
  `chosen`      DECIMAL(8,2)  NOT NULL,
  `is_correct`  TINYINT(1)    NOT NULL,
  `user_id`     INT           NOT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fv_ans` (`task_id`, `round`, `check_id`),
  KEY `ix_fv_ans_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `4a_fuel_verify_settings` (`k`, `v`, `note`) VALUES
  ('options',     '4',                       'επιλογές ανά γραμμή, μαζί με τη σωστή'),
  ('near_deltas', '0.25,0.50,0.75,1.00',     '(γ): απόσταση, τυχαία από τη λίστα, με τυχαίο πρόσημο'),
  ('other_type',  'AIR:ROAD,ROAD:AIR,AIR_CY:AIR', '(β): ο «άλλος» τύπος καυσίμου, ίδια εβδομάδα'),
  ('from_week',   '2026-10-26',              'verify με week_start πριν από αυτό: παλιά ροή (done)'),
  ('max_draws',   '50',                      'όριο κληρώσεων της (γ) μέχρι 4 διαφορετικές τιμές')
ON DUPLICATE KEY UPDATE `k` = `k`;

INSERT INTO `4a_fuel_verify_checks`
  (`task_code`, `client_code`, `station_country`, `cms_service`, `zone`, `fuel_type`, `sort_order`, `active`) VALUES
  ('fuel_weekly_verify', '1731000',  'GR', 'S1003', 'Z1', 'AIR',    10, 1),
  ('fuel_weekly_verify', '1731000',  'GR', 'S1010', 'Z1', 'ROAD',   20, 1),
  ('fuel_weekly_verify', '1731000',  'GR', 'S1027', 'Z9', 'AIR_CY', 30, 1),
  ('fuel_weekly_verify', '40006012', 'CY', 'S1012', 'Z1', 'AIR',    40, 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 4 γραμμές
SELECT `id`, `client_code`, `station_country`, `cms_service`, `zone`, `fuel_type`, `active`
  FROM `4a_fuel_verify_checks` ORDER BY `task_code`, `sort_order`, `id`;

-- Συμφωνία με τον κατάλογο CMS (4a_fuel_cms_services): ίδιος τύπος
-- καυσίμου και η ζώνη μέσα στις ζώνες της υπηρεσίας. Αναμενόμενο: 0
SELECT COUNT(*) AS `ασυμφωνίες_καταλόγου`
  FROM `4a_fuel_verify_checks` k
 WHERE k.`active` = 1
   AND NOT EXISTS (SELECT 1 FROM `4a_fuel_cms_services` s
                    WHERE s.`active` = 1
                      AND s.`cms_service` = k.`cms_service`
                      AND s.`station_country` = k.`station_country`
                      AND s.`fuel_type` = k.`fuel_type`
                      AND FIND_IN_SET(k.`zone`, s.`zones`) > 0);

-- Αναμενόμενο: 5 ρυθμίσεις
SELECT `k`, `v` FROM `4a_fuel_verify_settings` ORDER BY `k`;
