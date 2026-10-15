-- =====================================================================
--  rollback/2026-10-15b_ui_texts.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-15b: σβήνει τον πίνακα 4a_ui_texts.
--
--  ΠΡΩΤΑ το frontend: revert του commit του Α1 και push. Αλλιώς οι
--  σελίδες δείχνουν [κλειδί] αντί για κείμενα. Μετά το endpoint
--  api/ui_texts.php από το αντίγραφο του Β3 (ή σβήσιμο, αφού είναι νέο).
--
--  Idempotent: DROP TABLE IF EXISTS.
--
--  MySQL 8.4.6.
-- =====================================================================

DROP TABLE IF EXISTS `4a_ui_texts`;

-- Αναμενόμενο: 0
SELECT COUNT(*) AS `πίνακας_4a_ui_texts`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '4a_ui_texts';
