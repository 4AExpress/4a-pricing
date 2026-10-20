-- =====================================================================
--  rollback/2026-10-23c_ui_texts_verifier.rollback.sql
--
--  ΑΝΤΙΣΤΡΟΦΟ του 2026-10-23c: σβήνει ΜΟΝΟ τα 63 κλειδιά του επαληθευτή.
--  Τα κείμενα των 2026-10-15b / 2026-10-19b / 2026-10-21a μένουν ως έχουν.
--
--  Πριν από αυτό, αν ο επαληθευτής είναι ενεργός: from_week σε μακρινή
--  Δευτέρα ή rollback του 2026-10-23a, αλλιώς η οθόνη δείχνει [κλειδί].
--
--  MySQL 8.4.6. Idempotent.
-- =====================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM `4a_ui_texts`
 WHERE `scope` = 'tasks'
   AND `k` IN ('vf_title', 'vf_hint', 'vf_col_client', 'vf_col_zone', 'vf_col_service', 'vf_col_type',
               'vf_col_pick', 'vf_zone', 'vf_option', 'vf_expected', 'vf_send', 'vf_cancel',
               'vf_tip_send', 'vf_gate', 'vf_line_ok', 'vf_line_wrong', 'vf_done', 'vf_wrong',
               'vf_locked', 'vf_strip_locked', 'vf_d3_ok', 'vf_alert', 'vf_alert_line', 'vf_line_values',
               'vf_tip_dl_locked', 'vf_reset_btn', 'vf_tip_reset', 'vf_reset_title', 'vf_reset_hint',
               'vf_reason', 'vf_reset_send', 'vf_tip_reset_self', 'vf_ov_btn', 'vf_ov_title',
               'vf_ov_warn', 'vf_ov_send', 'vf_ov_box', 'vf_ov_ack', 'vf_tip_ack', 'vf_ev_ok',
               'vf_ev_wrong', 'vf_ev_reset', 'vf_ev_override', 'vf_hist_line', 'vf_err_off',
               'vf_err_config', 'vf_err_no_prices', 'vf_err_draw', 'vf_err_forbidden', 'vf_err_owner',
               'vf_err_locked', 'vf_err_reload', 'vf_err_incomplete', 'vf_err_bad_value',
               'vf_err_not_locked', 'vf_err_reset_self', 'vf_err_reason', 'vf_err_admin',
               'vf_err_use_verifier', 'vf_err_no_download', 'color_vf_wrong', 'color_vf_ok',
               'color_vf_pick');

-- Αναμενόμενο: clients 2 · common 3 · tasks 78
SELECT `scope`, COUNT(*) AS `κλειδιά`
  FROM `4a_ui_texts` GROUP BY `scope` ORDER BY `scope`;
