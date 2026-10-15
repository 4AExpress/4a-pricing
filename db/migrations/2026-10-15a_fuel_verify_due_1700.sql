-- =====================================================================
--  2026-10-15a_fuel_verify_due_1700.sql
--
--  ΦΑΣΗ 5, Π6 (σημείο 6 του ανασχεδιασμού Π2). ΑΠΟΦΑΣΗ 15/10: η
--  επαλήθευση του επίναυλου λήγει Δευτέρα 17:00 (Αθήνα), όχι 12:00.
--  Το παράθυρο επαλήθευσης είναι Δευ 00:00–17:00.
--
--  1. Κανόνας προθεσμίας του verify (στήλες του 14b): 0 / 12:00 -> 0 / 17:00.
--     Ο κανόνας της fuel_weekly (-3 / 19:00) ΔΕΝ αλλάζει.
--  2. Όρια χρώματος του verify (στήλες του 08c): 12 / 3 -> 17 / 3.
--     Πορτοκαλί από Δευ 00:00 (άνοιγμα του παραθύρου), ΕΠΕΙΓΟΝ από 14:00.
--  3. ΜΟΝΟ η #88 (verify 19/10): 2026-10-19 12:00 -> 2026-10-19 17:00,
--     με γεγονός στο ιστορικό της. Η #87 (fuel_weekly 19/10) ΔΕΝ αλλάζει.
--
--  Το due_at γράφεται σε ώρα Αθήνας, όχι UTC (απόφαση 08/10,
--  HANDOFF_fuel_phase1.md), όπως όλες οι εργασίες.
--
--  Idempotent: κάθε UPDATE απαιτεί την τρέχουσα τιμή (0 γραμμές αν
--  άλλαξε οτιδήποτε ή αν έχει ήδη τρέξει). Το γεγονός ιστορικού γράφεται
--  μόνο αν το UPDATE της #88 άλλαξε γραμμή, και μόνο μία φορά (σήμα
--  meta.migration).
--
--  Τρέχει ΜΕΤΑ τα 2026-10-14b και 2026-10-14c. Καμία αλλαγή κώδικα: η
--  προθεσμία και τα όρια διαβάζονται ήδη από τη βάση (Π3).
--  Αντίστροφο: rollback/2026-10-15a_fuel_verify_due_1700.rollback.sql
--
--  MySQL 8.4.6.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── 1. Κανόνας προθεσμίας του verify ──────────────────────────────────
UPDATE `4a_task_types` SET `due_time` = '17:00:00' WHERE `code` = 'fuel_weekly_verify' AND `due_offset_days` = 0 AND `due_time` = '12:00:00';

-- ── 2. Όρια χρώματος του verify ───────────────────────────────────────
UPDATE `4a_task_types` SET `due_warn_hours` = 17 WHERE `code` = 'fuel_weekly_verify' AND `due_warn_hours` = 12 AND `due_urgent_hours` = 3;

-- ── 3. Προθεσμία της #88 ──────────────────────────────────────────────
UPDATE `4a_tasks` SET `due_at` = '2026-10-19 17:00:00' WHERE `id` = 88 AND `task_code` = 'fuel_weekly_verify' AND `due_at` = '2026-10-19 12:00:00';
SET @c15 := ROW_COUNT();

-- ── 4. Ιστορικό της εργασίας (σύστημα: actor_id NULL) ─────────────────
INSERT INTO `4a_task_events` (`task_id`, `event`, `actor_id`, `note`, `meta`)
SELECT t.`id`, 'note', NULL,
       'διόρθωση προθεσμίας verify σε Δευ 17:00 — Π2',
       JSON_OBJECT('migration', '2026-10-15a', 'from', '2026-10-19 12:00:00', 'to', '2026-10-19 17:00:00')
  FROM `4a_tasks` t
 WHERE @c15 = 1
   AND t.`id` = 88 AND t.`task_code` = 'fuel_weekly_verify' AND t.`due_at` = '2026-10-19 17:00:00'
   AND NOT EXISTS (SELECT 1 FROM `4a_task_events` e
                    WHERE e.`task_id` = t.`id` AND e.`event` = 'note'
                      AND JSON_UNQUOTE(JSON_EXTRACT(e.`meta`, '$.migration')) = '2026-10-15a');

-- ── 5. Επαλήθευση ─────────────────────────────────────────────────────
-- Αναμενόμενο: fuel_weekly -3 19:00:00 24 4 · fuel_weekly_verify 0 17:00:00 17 3
SELECT `code`, `due_offset_days`, `due_time`, `due_warn_hours`, `due_urgent_hours`
  FROM `4a_task_types` WHERE `code` IN ('fuel_weekly', 'fuel_weekly_verify') ORDER BY `sort_order`, `code`;

-- Αναμενόμενο: 87 fuel_weekly 2026-10-16 17:00:00 · 88 fuel_weekly_verify 2026-10-19 17:00:00
SELECT `id`, `task_code`, `status`, `due_at`
  FROM `4a_tasks` WHERE `id` IN (87, 88) ORDER BY `id`;

-- Αναμενόμενο: 1
SELECT COUNT(*) AS `γεγονότα_15a`
  FROM `4a_task_events`
 WHERE `task_id` = 88 AND `event` = 'note'
   AND JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.migration')) = '2026-10-15a';
