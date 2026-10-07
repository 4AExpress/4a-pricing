-- 2026-10-07d_fuel_cache_mem.sql
--
-- ΜΝΗΜΗ ΤΟΥ CACHE ΕΠΙΝΑΥΛΟΥ για το api/fuel_catalog.php.
--
-- Μια γραμμη, id = 1. Κραταει την τελευταια ΚΑΛΗ αναγνωση του
-- data/fuel_surcharge_cache.json απο το GitHub, ωστε το endpoint να μην
-- καλει το GitHub σε καθε φορτωση. Το endpoint τη θεωρει φρεσκια για 10
-- λεπτα και μετα ξαναδιαβαζει.
--
--   body     το JSON αυτουσιο, οπως ηρθε
--   read_at  ποτε διαβαστηκε απο το GitHub, ωρα του server της βασης
--
-- Το endpoint ΔΕΝ εκτελει DDL. Αν ο πινακας λειπει, απαντα με τον καταλογο
-- και weeks κενο, και γραφει error_log.
--
-- Αντικατασταση μονο μετα απο ελεγχο περιεχομενου (fuel_cache_invalid στο
-- fuel_lib.php). Ενα κακο αρχειο δεν σβηνει το προηγουμενο καλο αντιγραφο.
--
-- MySQL 8.4.6. Ιδεμποτεντικο.

CREATE TABLE IF NOT EXISTS `4a_fuel_cache_mem` (
  `id`      tinyint unsigned NOT NULL,
  `body`    mediumtext       NOT NULL,
  `read_at` datetime         NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
