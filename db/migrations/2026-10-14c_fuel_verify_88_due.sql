-- =====================================================================
--  2026-10-14c_fuel_verify_88_due.sql
--
--  ΦΑΣΗ 5, Π3. ΔΙΟΡΘΩΣΗ ΠΡΟΘΕΣΜΙΑΣ ΜΙΑΣ ΕΡΓΑΣΙΑΣ: #88 (verify 19/10).
--
--  Η #88 δημιουργήθηκε 12/10 με την παλιά προθεσμία του κώδικα,
--  Παρασκευή 2026-10-16 17:00. Με τα όρια 12 / 3 του 2026-10-14b θα
--  γινόταν ΕΠΕΙΓΟΝ την Παρασκευή και εκπρόθεσμη όλο το Σαββατοκύριακο,
--  ενώ η επαλήθευση γίνεται μόνο τη Δευτέρα: ψεύτικος συναγερμός.
--  Νέα προθεσμία = ο κανόνας του 14b για το verify (0 / 12:00):
--  Δευτέρα 2026-10-19 12:00.
--
--  Το due_at γράφεται σε ώρα Αθήνας, όχι UTC (απόφαση 08/10,
--  HANDOFF_fuel_phase1.md), όπως όλες οι εργασίες.
--
--  ΜΟΝΟ η #88. Η #87 (fuel_weekly 19/10) ΔΕΝ αλλάζει, μένει 17:00 (Ο1).
--
--  Idempotent: το UPDATE απαιτεί id, τύπο ΚΑΙ την τρέχουσα τιμή
--  (0 γραμμές αν άλλαξε οτιδήποτε). Το γεγονός ιστορικού γράφεται μόνο
--  αν το UPDATE άλλαξε γραμμή, και μόνο μία φορά (σήμα meta.migration).
--
--  Τρέχει ΜΕΤΑ το 2026-10-14b, στο ίδιο Β4.
--  Αντίστροφο: rollback/2026-10-14c_fuel_verify_88_due.rollback.sql
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. Προθεσμία ──────────────────────────────────────────────────────
UPDATE `4a_tasks` SET `due_at` = '2026-10-19 12:00:00' WHERE `id` = 88 AND `task_code` = 'fuel_weekly_verify' AND `due_at` = '2026-10-16 17:00:00';
SET @c14 := ROW_COUNT();

-- ── 2. Ιστορικό της εργασίας (σύστημα: actor_id NULL) ─────────────────
INSERT INTO `4a_task_events` (`task_id`, `event`, `actor_id`, `note`, `meta`)
SELECT t.`id`, 'note', NULL,
       'διόρθωση προθεσμίας verify σε Δευ 12:00 — Π3',
       JSON_OBJECT('migration', '2026-10-14c', 'from', '2026-10-16 17:00:00', 'to', '2026-10-19 12:00:00')
  FROM `4a_tasks` t
 WHERE @c14 = 1
   AND t.`id` = 88 AND t.`task_code` = 'fuel_weekly_verify' AND t.`due_at` = '2026-10-19 12:00:00'
   AND NOT EXISTS (SELECT 1 FROM `4a_task_events` e
                    WHERE e.`task_id` = t.`id` AND e.`event` = 'note'
                      AND JSON_UNQUOTE(JSON_EXTRACT(e.`meta`, '$.migration')) = '2026-10-14c');

-- ── 3. Επαλήθευση ─────────────────────────────────────────────────────
-- Αναμενόμενο: 87 fuel_weekly 2026-10-16 17:00:00 · 88 fuel_weekly_verify 2026-10-19 12:00:00
SELECT `id`, `task_code`, `status`, `due_at`
  FROM `4a_tasks` WHERE `id` IN (87, 88) ORDER BY `id`;

-- Αναμενόμενο: 1
SELECT COUNT(*) AS `γεγονότα_14c`
  FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-14c';
