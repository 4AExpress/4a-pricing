-- =====================================================================
--  2026-10-23b_fuel_verify_values_perm.sql
--
--  ΕΠΑΛΗΘΕΥΤΗΣ: ποιος βλέπει τις ΑΝΑΜΕΝΟΜΕΝΕΣ τιμές (απόφαση 19/10).
--  Ρύθμιση ανά δικαίωμα, ΟΧΙ έλεγχος ρόλου στον κώδικα:
--
--    module fuel_verify_values, can_view = 1  -> βλέπει τις αναμενόμενες
--        τιμές στον επαληθευτή, το κόκκινο σήμα λάθους, τις τιμές του
--        payload της εβδομάδας, και κατεβάζει το αρχείο CMS (Ε2).
--    can_view = 0  -> τίποτα από αυτά, ούτε στο JSON του API.
--
--  Σήμερα: ο administrator το έχει αυτόματα (όλα τα ενεργά modules,
--  auth.php). Ρόλοι 2 manager, 3 staff, 4 readonly, 5 trainee: 0, ρητά.
--  Για τους managers αργότερα: can_view = 1 στη γραμμή (2, ...), ή από
--  την οθόνη ρόλων. Καμία αλλαγή κώδικα.
--
--  Αντίστροφο: rollback/2026-10-23b_fuel_verify_values_perm.rollback.sql
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `modules` (`id`, `label`, `icon`, `sort_order`, `active`) VALUES
  ('fuel_verify_values', 'Επαλήθευση επίναυλου: αναμενόμενες τιμές', 'ti-eye', 23, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `icon` = VALUES(`icon`);

INSERT INTO `role_permissions`
  (`role_id`, `module_id`, `can_view`, `can_add`, `can_edit`, `can_delete`, `can_export`)
VALUES
  (2, 'fuel_verify_values', 0, 0, 0, 0, 0),   -- manager: ΟΧΙ (σήμερα)
  (3, 'fuel_verify_values', 0, 0, 0, 0, 0),   -- staff
  (4, 'fuel_verify_values', 0, 0, 0, 0, 0),   -- readonly
  (5, 'fuel_verify_values', 0, 0, 0, 0, 0)    -- trainee
ON DUPLICATE KEY UPDATE `module_id` = `module_id`;

-- ── Επαλήθευση ────────────────────────────────────────────────────────
-- Αναμενόμενο: 1 γραμμή, active 1
SELECT `id`, `label`, `icon`, `sort_order`, `active`
  FROM `modules` WHERE `id` = 'fuel_verify_values';

-- Αναμενόμενο: 4 γραμμές (ρόλοι 2–5), όλες can_view 0
SELECT `role_id`, `can_view`, `can_add`, `can_edit`, `can_delete`, `can_export`
  FROM `role_permissions` WHERE `module_id` = 'fuel_verify_values' ORDER BY `role_id`;
