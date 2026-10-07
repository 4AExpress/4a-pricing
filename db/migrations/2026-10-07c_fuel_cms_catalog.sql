-- 2026-10-07c_fuel_cms_catalog.sql
--
-- ΚΑΤΑΛΟΓΟΣ ΓΡΑΜΜΩΝ ΤΟΥ ΑΡΧΕΙΟΥ CMS ΓΙΑ ΤΟΝ ΓΕΝΙΚΟ ΕΠΙΝΑΥΛΟ
--
-- Δυο πινακες, μια πηγη αληθειας:
--
--   4a_fuel_cms_services    ενας κατalογος ανα υπηρεσια, σταθμο και
--                           κατευθυνση. Η στηλη zones ειναι λιστα, και ο
--                           κανονας παραγωγης την ανοιγει σε μια γραμμη
--                           αρχειου ανα ζωνη. 12 εγγραφες, 68 γραμμες.
--
--   4a_fuel_cms_extra_rows  γραμμες που ΔΕΝ ακολουθουν τον κανονα, με
--                           πληρεις στηλες, αυτουσιες. Σημερα μια, η
--                           ειδικη γραμμη Βουλγαριας.
--
-- ΕΝΩΣΗ 68 συν 1 ισον 69, ιδιο με το db/seeds/fuel_cms_rows_seed.csv, που
-- ειναι το περιεχομενο που δεχτηκε το CMS στις 07-10-2026.
--
-- ΚΑΝΟΝΑΣ ΠΑΡΑΓΩΓΗΣ, υλοποιημενος στη fuel_cms_rows() του api/fuel_lib.php
--
--   export  Station=station_country, OriginZone κενο,
--           OriginCountry=station_country, Zone=Zx, Delivery κενο
--   import  Station=station_country, OriginZone=Zx,
--           OriginCountry κενο, Zone κενο, Delivery=station_country
--
-- ΓΙΑΤΙ ΞΕΧΩΡΙΣΤΟΣ ΠΙΝΑΚΑΣ ΓΙΑ ΤΙΣ ΕΞΑΙΡΕΣΕΙΣ: η γραμμη Βουλγαριας εχει
-- ΚΑΙ OriginCountry ΚΑΙ Delivery συμπληρωμενα, δηλαδη δεν ταιριαζει σε
-- κανενα απο τα δυο προτυπα. Χωρεμενη στον κανονα θα τον εσπαγε.
--
-- ΟΙ S1026, S1027, S1029, S1032 ΖΟΥΝ ΜΟΝΟ ΕΔΩ. Δεν υπαρχουν στον
-- 4a_services, επιβεβαιωθηκε με query. Γι αυτο ο πινακας κρατα δικο του
-- service_name και δικο του fuel_type, και δεν κανει join στις υπηρεσιες.
--
-- Η S1003 και η S1012 εμφανιζονται ΔΥΟ φορες, μια για σταθμο GR και μια
-- για σταθμο CY. Ιδια τιμη επιναυλου, διαφορετικη γραμμη αρχειου.
--
-- Το visible_scope εχει προεπιλογη ιση με το station_country. Η Φαση 5 θα
-- το χρησιμοποιησει ωστε καθε χρηστης να βλεπει τη χωρα του.
--
-- MySQL 8.4.6. Καμια συνταξη MariaDB.

CREATE TABLE IF NOT EXISTS `4a_fuel_cms_services` (
  `id`              int unsigned            NOT NULL AUTO_INCREMENT,
  `cms_service`     varchar(16)             NOT NULL,
  `service_name`    varchar(80)             NOT NULL,
  `station_country` enum('GR','CY')         NOT NULL,
  `direction`       enum('export','import') NOT NULL,
  `zones`           varchar(120)            NOT NULL,
  `fuel_type`       varchar(16)             NOT NULL,
  `is_combi`        tinyint(1)              NOT NULL DEFAULT 0,
  `visible_scope`   enum('GR','CY','BOTH')  NOT NULL,
  `sort_order`      int                     NOT NULL DEFAULT 0,
  `active`          tinyint(1)              NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_svc_station_dir` (`cms_service`, `station_country`, `direction`),
  KEY `ix_scope` (`visible_scope`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `4a_fuel_cms_extra_rows` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `station_country`  varchar(4)   NOT NULL DEFAULT '',
  `origin_zone`      varchar(8)   NOT NULL DEFAULT '',
  `origin_country`   varchar(4)   NOT NULL DEFAULT '',
  `zone`             varchar(8)   NOT NULL DEFAULT '',
  `delivery_country` varchar(4)   NOT NULL DEFAULT '',
  `cms_service`      varchar(16)  NOT NULL,
  `service_name`     varchar(80)  NOT NULL DEFAULT '',
  `fuel_type`        varchar(16)  NOT NULL,
  `note`             varchar(160) NOT NULL DEFAULT '',
  `sort_order`       int          NOT NULL DEFAULT 0,
  `active`           tinyint(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_extra_identity` (`station_country`, `origin_zone`,
    `origin_country`, `zone`, `delivery_country`, `cms_service`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Τα δεδομενα παραχθηκαν ΑΠΟ το seed csv με script, χωρις χειροκινητη
-- μεταγραφη τιμων.

INSERT INTO `4a_fuel_cms_services`
  (`cms_service`, `service_name`, `station_country`, `direction`, `zones`,
   `fuel_type`, `is_combi`, `visible_scope`, `sort_order`, `active`)
VALUES
  ('S1003', 'Export Express', 'GR', 'export', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10', 'AIR', 0, 'GR', 10, 1),
  ('S1012', 'IMPORT EXPRESS', 'GR', 'import', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7', 'AIR', 0, 'GR', 100, 1),
  ('S1026', 'CARGO EXPORT', 'GR', 'export', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7', 'AIR', 0, 'GR', 170, 1),
  ('S1029', 'Bio Express', 'GR', 'export', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10', 'AIR', 0, 'GR', 250, 1),
  ('S1032', 'Airletter 500gr', 'GR', 'export', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7,Z9,Z10', 'AIR', 0, 'GR', 340, 1),
  ('S1027', 'Air Cyprus', 'GR', 'export', 'Z9', 'AIR_CY', 0, 'GR', 430, 1),
  ('S1010', 'Road 4-8 export', 'GR', 'export', 'Z1,Z2,Z3', 'ROAD', 0, 'GR', 440, 1),
  ('S1041', 'IMPORT ROAD', 'GR', 'import', 'Z1,Z2,Z3', 'ROAD', 0, 'GR', 470, 1),
  ('S1003', 'Export Express', 'CY', 'export', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7', 'AIR', 0, 'CY', 500, 1),
  ('S1012', 'IMPORT EXPRESS', 'CY', 'import', 'Z1,Z2,Z3,Z4,Z5,Z6,Z7', 'AIR', 0, 'CY', 570, 1),
  ('S1050', 'EXPORTAIRANDROAD', 'CY', 'export', 'Z1,Z2,Z3', 'ROAD', 1, 'CY', 640, 1),
  ('S1051', 'IMPORT ROAD AND AIR', 'CY', 'import', 'Z1,Z2,Z3', 'ROAD', 1, 'CY', 670, 1)
ON DUPLICATE KEY UPDATE
  `service_name`  = VALUES(`service_name`),
  `zones`         = VALUES(`zones`),
  `fuel_type`     = VALUES(`fuel_type`),
  `is_combi`      = VALUES(`is_combi`),
  `visible_scope` = VALUES(`visible_scope`),
  `sort_order`    = VALUES(`sort_order`),
  `active`        = VALUES(`active`);

INSERT INTO `4a_fuel_cms_extra_rows`
  (`station_country`, `origin_zone`, `origin_country`, `zone`, `delivery_country`,
   `cms_service`, `service_name`, `fuel_type`, `note`, `sort_order`, `active`)
VALUES
  ('GR', '', 'GR', 'Z5', 'BG', 'S1026', 'CARGO EXPORT', 'AIR', 'ειδική γραμμή Βουλγαρίας', 240, 1)
ON DUPLICATE KEY UPDATE
  `service_name` = VALUES(`service_name`),
  `fuel_type`    = VALUES(`fuel_type`),
  `note`         = VALUES(`note`),
  `active`       = VALUES(`active`);
