-- ============================================================================
-- 4A Express — Migration 001: Αρχείο προσφορών & εξερχόμενης αλληλογραφίας
-- ----------------------------------------------------------------------------
-- Εκτελείται ΜΙΑ φορά, χειροκίνητα (phpMyAdmin ή CLI).
-- ΔΕΝ χρησιμοποιεί CREATE TABLE IF NOT EXISTS μέσα σε endpoint.
--
-- Βάση:      dbdkhuhge5dmn2
-- MySQL:     8.0 (utf8mb4_0900_ai_ci)
-- Εξαρτάται: 4a_clients(id) bigint, 4a_users(id)
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1. 4a_offer_seq — ατομικός μετρητής αριθμού προσφοράς
-- ---------------------------------------------------------------------------
-- Μία γραμμή ανά έτος. Το UPDATE ... LAST_INSERT_ID() εγγυάται ατομικότητα
-- ακόμη και με ταυτόχρονους χρήστες — σε αντίθεση με τον σημερινό
-- client-side υπολογισμό max+1, που παράγει διπλούς αριθμούς.
-- ---------------------------------------------------------------------------
CREATE TABLE `4a_offer_seq` (
  `year`       SMALLINT UNSIGNED NOT NULL,
  `last_num`   INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------------
-- 2. 4a_offers — μία γραμμή ανά προσφορά ΚΑΙ ανά αναθεώρηση
-- ---------------------------------------------------------------------------
-- Αναθεώρηση = νέα γραμμή με ίδιο offer_number, revision+1.
-- Η προηγούμενη σημειώνεται status='superseded' + superseded_by.
--
-- ΚΡΙΣΙΜΟ: το `snapshot` κρατά ΟΛΑ τα δεδομένα υπολογισμού τη στιγμή
-- της αποστολής (όλες οι ζώνες, fuel %, έκδοση τιμοκαταλόγου, surcharge,
-- markup ανά γραμμή). Η προβολή παλιάς προσφοράς ΔΕΝ επιτρέπεται να
-- ξαναδιαβάσει ζωντανές ρυθμίσεις. Μια σταλμένη προσφορά δεν αλλάζει ποτέ.
-- ---------------------------------------------------------------------------
CREATE TABLE `4a_offers` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Ταυτότητα
  `offer_number`   VARCHAR(20)  NOT NULL COMMENT '4A-2026-0042 (κοινό στις αναθεωρήσεις)',
  `revision`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `offer_ref`      VARCHAR(30)  NOT NULL COMMENT '4A-2026-0042-R1 — μοναδικό',

  -- Πελάτης (denormalised name: ο πελάτης μπορεί να μετονομαστεί αργότερα,
  -- η προσφορά πρέπει να δείχνει το όνομα ΤΗΣ ΣΤΙΓΜΗΣ που στάλθηκε)
  `client_id`      BIGINT       DEFAULT NULL,
  `client_name`    VARCHAR(200) NOT NULL,
  `client_afm`     VARCHAR(20)  DEFAULT NULL,
  `client_email`   VARCHAR(100) DEFAULT NULL,
  `country`        ENUM('GR','CY','EU','NONEU') NOT NULL DEFAULT 'GR',

  -- Κατάσταση
  `status`         ENUM('draft','sent','accepted','rejected','superseded','expired')
                   NOT NULL DEFAULT 'draft',
  `superseded_by`  BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK -> 4a_offers.id της αναθεώρησης',

  -- Το πλήρες αποτύπωμα υπολογισμού (βλ. σχόλιο παραπάνω)
  `snapshot`       JSON NOT NULL,
  `fuel_pct`       DECIMAL(6,3) DEFAULT NULL COMMENT 'επίναυλος καυσίμου ΤΗΣ ΣΤΙΓΜΗΣ',
  `tariff_version` VARCHAR(30)  DEFAULT NULL,
  `validity_days`  SMALLINT UNSIGNED DEFAULT 30,
  `valid_until`    DATE         DEFAULT NULL,

  -- Ποιος/πότε
  `created_by`     BIGINT       DEFAULT NULL COMMENT 'FK -> 4a_users.id',
  `created_by_name` VARCHAR(100) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`        DATETIME     DEFAULT NULL,
  `accepted_at`    DATETIME     DEFAULT NULL,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offer_ref` (`offer_ref`),
  KEY `ix_client`   (`client_id`, `created_at`),
  KEY `ix_number`   (`offer_number`, `revision`),
  KEY `ix_status`   (`status`, `created_at`),
  KEY `ix_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------------
-- 3. 4a_outbound_log — κάθε έγγραφο που φεύγει (ή έρχεται)
-- ---------------------------------------------------------------------------
-- Ξεχωριστός πίνακας από το 4a_offers γιατί η ΙΔΙΑ προσφορά μπορεί να
-- σταλεί πολλές φορές (επαναποστολή, δεύτερος παραλήπτης) — και θέλουμε
-- ιστορικό κάθε αποστολής, όχι μόνο της τελευταίας.
--
-- direction: πρόβλεψη για μελλοντική σύνδεση IMAP (εισερχόμενες αποδοχές).
-- Σήμερα γράφεται πάντα 'out'.
-- ---------------------------------------------------------------------------
CREATE TABLE `4a_outbound_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `direction`     ENUM('out','in') NOT NULL DEFAULT 'out',
  `doc_type`      VARCHAR(30) NOT NULL COMMENT 'offer | cod_form | contract | acceptance_signed | other',

  -- Σύνδεση
  `offer_id`      BIGINT UNSIGNED DEFAULT NULL,
  `client_id`     BIGINT       DEFAULT NULL,
  `client_name`   VARCHAR(200) DEFAULT NULL,
  `reference`     VARCHAR(30)  DEFAULT NULL COMMENT 'offer_ref ή άλλο αναγνωριστικό',

  -- Email
  `sent_to`       VARCHAR(255) DEFAULT NULL,
  `sent_cc`       VARCHAR(255) DEFAULT NULL,
  `sent_bcc`      VARCHAR(255) DEFAULT NULL,
  `subject`       VARCHAR(255) DEFAULT NULL,

  -- Το ίδιο το αρχείο — το ΜΟΝΟ αμετάβλητο αντίγραφο αυτού που στάλθηκε
  `file_name`     VARCHAR(255) DEFAULT NULL,
  `file_path`     VARCHAR(255) DEFAULT NULL COMMENT 'ΣΧΕΤΙΚΗ διαδρομή: 2026/09/xxx.pdf',
  `file_sha256`   CHAR(64)     DEFAULT NULL,
  `file_bytes`    INT UNSIGNED DEFAULT NULL,

  -- Πρόβλεψη για IMAP (σήμερα NULL)
  `message_id`    VARCHAR(255) DEFAULT NULL,
  `in_reply_to`   VARCHAR(255) DEFAULT NULL,
  `thread_id`     VARCHAR(255) DEFAULT NULL,

  -- Αποτέλεσμα
  `status`        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `error_message` TEXT,

  -- Ποιος/πότε
  `actor_id`      BIGINT       DEFAULT NULL COMMENT 'FK -> 4a_users.id',
  `actor_name`    VARCHAR(100) DEFAULT NULL,
  `ip`            VARCHAR(45)  DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       DATETIME     DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `ix_offer`   (`offer_id`),
  KEY `ix_client`  (`client_id`, `created_at`),
  KEY `ix_type`    (`doc_type`, `created_at`),
  KEY `ix_status`  (`status`),
  KEY `ix_created` (`created_at`),
  KEY `ix_sha`     (`file_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------------
-- 4. Αρχικοποίηση μετρητή — ΣΥΝΕΧΕΙΑ από την υπάρχουσα αρίθμηση (επιλογή Α)
-- ---------------------------------------------------------------------------
-- Το nextOfferNumber() του frontend υπολόγιζε max ΑΓΝΟΩΝΤΑΣ το έτος
-- (regex 4A-\d{4}-(\d+), σύγκριση μόνο στο τελευταίο μέρος). Άρα ο
-- «μέγιστος» μπορεί να προέρχεται από περσινή προσφορά. Παίρνουμε τον
-- απόλυτο μέγιστο ανεξαρτήτως έτους και συνεχίζουμε από εκεί.
-- ---------------------------------------------------------------------------
INSERT INTO `4a_offer_seq` (`year`, `last_num`)
SELECT YEAR(CURDATE()),
       COALESCE(MAX(CAST(SUBSTRING_INDEX(`offer_number`, '-', -1) AS UNSIGNED)), 0)
FROM `4a_clients`
WHERE `offer_number` REGEXP '^4A-[0-9]{4}-[0-9]+$';


-- ---------------------------------------------------------------------------
-- 5. UNIQUE στο 4a_clients.offer_number — ΠΡΟΑΙΡΕΤΙΚΟ, ΜΗΝ ΤΟ ΤΡΕΞΕΙΣ ΤΥΦΛΑ
-- ---------------------------------------------------------------------------
-- Έλεγξε ΠΡΩΤΑ αν υπάρχουν ήδη διπλότυπα (πολύ πιθανό, λόγω του
-- client-side max+1 πάνω σε φιλτραρισμένη λίστα ανά pricelist_scope):
--
--   SELECT offer_number, COUNT(*) c FROM `4a_clients`
--   WHERE offer_number IS NOT NULL
--   GROUP BY offer_number HAVING c > 1;
--
-- Αν επιστρέψει κενό, τότε (και μόνο τότε):
--   ALTER TABLE `4a_clients` ADD UNIQUE KEY `uq_offer_number` (`offer_number`);


-- ---------------------------------------------------------------------------
-- Έλεγχος επιτυχίας
-- ---------------------------------------------------------------------------
-- SELECT * FROM `4a_offer_seq`;
-- SHOW TABLES LIKE '4a_off%';
-- SHOW TABLES LIKE '4a_outbound%';
