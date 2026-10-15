-- =====================================================================
--  2026-10-15b_ui_texts.sql
--
--  ΦΑΣΗ Α, Α1 (Π1): ΠΙΝΑΚΑΣ ΚΕΙΜΕΝΩΝ ΟΘΟΝΗΣ 4a_ui_texts.
--
--  Τα κείμενα που μέχρι σήμερα ζούσαν στον κώδικα (UI_TEMP του
--  tasks.html, NAV_TEMP του pricelist-clients.html, tooltip παράκαμψης
--  του tasks-badge.js) περνούν στη βάση. Η σελίδα τα διαβάζει από το
--  api/ui_texts.php μέσω του frontend/ui-texts.js.
--
--  Στήλες: scope (σελίδα ή common), k (κλειδί), kind (text ή color),
--  text. Για kind = color ΜΟΝΟ #rrggbb: CHECK εδώ, και ξανά στο endpoint.
--  Κλειδί που λείπει φαίνεται στην οθόνη ως [κλειδί], χωρίς κείμενο
--  εφεδρείας στον κώδικα (απόφαση 14/10).
--
--  Seed: ο πίνακας Δ του K4a_review_1014.md, όπως εγκρίθηκε 14/10, με
--  τις αλλαγές της ίδιας μέρας (fuel_uploaded με το σύμβολο αναμονής,
--  mine_empty_queue με το πλήθος της ουράς).
--
--  Collation: ΚΑΜΙΑ ρητή. Ο πίνακας παίρνει της βάσης, ώστε να μην
--  προστεθεί γραμμή στον έλεγχο collations του probe.
--
--  Idempotent: CREATE TABLE IF NOT EXISTS, και το seed ΔΕΝ ξαναγράφει
--  κείμενο που υπάρχει ήδη (ON DUPLICATE KEY UPDATE χωρίς αλλαγή).
--  Μελλοντικές διορθώσεις κειμένων γίνονται με ΝΕΟ migration (Υ2).
--
--  Αντίστροφο: rollback/2026-10-15b_ui_texts.rollback.sql
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. Πίνακας ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `4a_ui_texts` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `scope`      VARCHAR(20)   NOT NULL COMMENT 'σελίδα (tasks, clients) ή common',
  `k`          VARCHAR(60)   NOT NULL COMMENT 'κλειδί, όπως το ζητά ο κώδικας',
  `kind`       ENUM('text','color') NOT NULL DEFAULT 'text',
  `text`       VARCHAR(500)  NOT NULL COMMENT 'με {x} για τιμές από τα δεδομένα',
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ui_texts_scope_k` (`scope`, `k`),
  CONSTRAINT `chk_ui_texts_color` CHECK (`kind` <> 'color' OR `text` REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB;

-- ── 2. Κείμενα (πίνακας Δ, 14/10) ─────────────────────────────────────
INSERT INTO `4a_ui_texts` (`scope`, `k`, `kind`, `text`) VALUES
  ('common',  'link_icon',                    'text',  '🔗'),
  ('common',  'badge_override_tip',           'text',  '{n} παρακάμψεις διαχειριστή περιμένουν «Το είδα»'),
  ('tasks',   'task_group_client_link',       'text',  'Πελάτης'),
  ('tasks',   'task_group_client_link_title', 'text',  'Άνοιγμα πελάτη'),
  ('clients', 'clients_back_to_tasks',        'text',  'Εργασίες'),
  ('clients', 'clients_back_to_tasks_title',  'text',  'Πίσω στις εργασίες'),
  ('tasks',   'client_no_account',            'text',  'χωρίς AccountNo'),
  ('tasks',   'client_no_offer',              'text',  'χωρίς προσφορά'),
  ('tasks',   'mine_empty_queue',             'text',  '→ Δείτε την Ουρά αδιάθετων ({n})'),
  ('tasks',   'fuel_next_todo',               'text',  '◷ ΠΡΟΣ ΕΚΤΕΛΕΣΗ · προθεσμία {due}'),
  ('tasks',   'fuel_uploaded',                'text',  '⏳ Ανέβηκε · εκκρεμεί επαλήθευση'),
  ('tasks',   'fuel_next_ready',              'text',  '✓ Έτοιμη · ισχύει από {day}'),
  ('tasks',   'fuel_now_ok',                  'text',  '✓ Σε ισχύ · CMS ενημερωμένο'),
  ('tasks',   'fuel_now_missing',             'text',  '⚠ Σε ισχύ · CMS ΜΗ ενημερωμένο'),
  ('tasks',   'fuel_older',                   'text',  'Παλαιότερες εβδομάδες ({n})'),
  ('tasks',   'grp_tip_collapse',             'text',  'Collapse — Μάζεμα της ομάδας'),
  ('tasks',   'grp_tip_expand',               'text',  'Expand — Άνοιγμα της ομάδας'),
  ('tasks',   'color_open_head',              'color', '#FCFF29')
ON DUPLICATE KEY UPDATE `k` = `k`;

-- ── 3. Επαλήθευση ─────────────────────────────────────────────────────
-- Αναμενόμενο: clients 2 · common 2 · tasks 14
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;

-- Αναμενόμενο: η στήλη text με collation = @@collation_database
SELECT `COLUMN_NAME`, `COLLATION_NAME`, @@collation_database AS `βάση`
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_ui_texts' AND COLUMN_NAME IN ('scope', 'k', 'text')
 ORDER BY `ORDINAL_POSITION`;
