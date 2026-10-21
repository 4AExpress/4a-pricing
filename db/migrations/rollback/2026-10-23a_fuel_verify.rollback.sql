-- =====================================================================
--  rollback/2026-10-23a_fuel_verify.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-23a: σβήνει τους 4 πίνακες του επαληθευτή.
--  Ο κώδικας χωρίς αυτούς συμπεριφέρεται όπως πριν (done με Δ-Α8).
--
--  ΠΡΟΣΟΧΗ: σβήνει ΚΑΙ τις απαντήσεις που δόθηκαν. Πριν από αυτό, αν
--  υπάρχουν γραμμές στον 4a_fuel_verify_answers, κράτα αντίγραφο.
--  Τα γεγονότα verify_ok / verify_wrong / verify_reset του ιστορικού
--  (4a_task_events) ΜΕΝΟΥΝ.
--
--  Γρήγορο φρένο ΧΩΡΙΣ rollback: UPDATE `4a_fuel_verify_settings`
--  SET `v` = «επόμενη Δευτέρα» WHERE `k` = «from_week» (τα «» = εισαγωγικά SQL).
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Αναμενόμενο πριν το DROP: πόσες απαντήσεις χάνονται
SELECT COUNT(*) AS `απαντήσεις_που_σβήνονται` FROM `4a_fuel_verify_answers`;

DROP TABLE IF EXISTS `4a_fuel_verify_answers`;
DROP TABLE IF EXISTS `4a_fuel_verify_options`;
DROP TABLE IF EXISTS `4a_fuel_verify_settings`;
DROP TABLE IF EXISTS `4a_fuel_verify_checks`;

-- Αναμενόμενο: 0
SELECT COUNT(*) AS `πίνακες_επαληθευτή`
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME IN ('4a_fuel_verify_checks', '4a_fuel_verify_settings',
                      '4a_fuel_verify_options', '4a_fuel_verify_answers');
