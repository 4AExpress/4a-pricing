// Εργασίες ΠΕΛΑΤΗ σε όλες τις καταστάσεις, για τον έλεγχο byte-προς-byte
// του tasks.test.js. Το tasks_client_rows.golden.json είναι η έξοδος του
// tasks.html στο commit 20588f5 πάνω σε αυτές τις εργασίες: οι αλλαγές για
// τις εργασίες ΣΥΣΤΗΜΑ δεν πρέπει να αλλάξουν ούτε ένα byte εδώ.
const STATUSES = {
  open:        { code: 'open',        label: 'Ανοιχτή',          color: '#1565c0', sort_order: 10 },
  in_progress: { code: 'in_progress', label: 'Σε εξέλιξη',       color: '#ef6c00', sort_order: 20 },
  paused:      { code: 'paused',      label: 'Σε παύση',         color: '#00838f', sort_order: 25, icon: '⏸' },
  ready:       { code: 'ready',       label: 'Έτοιμη για ολοκλήρωση', color: '#2e7d32', sort_order: 28 },
  done:        { code: 'done',        label: 'Ολοκληρωμένη',     color: '#9e9e9e', sort_order: 30 },
  na:          { code: 'na',          label: 'Δεν εφαρμόζεται',  color: '#cfcfcf', sort_order: 40 },
  locked:      { code: 'locked',      label: 'Περιμένει',        color: '#607d8b', sort_order: 5, icon: '🔒' },
};
const BASE = { assigned_to: null, assigned_name: null, status: 'open', needs_attention: 0, locked: 0,
  is_demo: 0, close_reason: null, offer_number: 'OF-1', ready: true, ready_enforced: false,
  client_country: 'GR', client_account: 'ACC', task_icon: null, task_title_template: null,
  depends_on: null, due_at: '2026-10-09 17:00:00', action_url: null, action_label: null };
const c = (id, cid, name, extra) => Object.assign({}, BASE, {
  id, client_id: cid, client_name: name, task_code: 'open_code', task_label: 'Άνοιγμα κωδικού',
  payload: JSON.stringify({ account: 'ACC', services: ['S1003', 'S1027'] }) }, extra || {});
const TASKS = [
  c(1, 10, 'Α Ανοιχτή'),
  c(2, 10, 'Α Ανοιχτή', { status: 'in_progress', assigned_to: 1, assigned_name: 'Εγώ', task_code: 'cms_rates', task_label: 'Τιμές CMS',
                          depends_on: 'open_code', display_status: 'ready' }),
  c(3, 11, 'Β Ξένη', { status: 'in_progress', assigned_to: 2, assigned_name: 'Άλλος', ready: false, ready_enforced: true, ready_hint: 'Λείπει AccountNo' }),
  c(4, 11, 'Β Ξένη', { status: 'paused', assigned_to: 1, assigned_name: 'Εγώ', ready: false, ready_hint: 'Περιμένει απάντηση' }),
  c(5, 12, 'Γ Κλειδωμένη', { locked: 1, depends_on: 'open_code', blocked_by_label: 'Άνοιγμα κωδικού', display_status: 'locked' }),
  c(6, 13, 'Δ Κλειστή', { status: 'done', assigned_to: 1, assigned_name: 'Εγώ' }),
  c(7, 13, 'Δ Κλειστή', { status: 'na', close_reason: 'δεν έχει COD', task_code: 'cms_cod', task_label: 'COD' }),
  c(8, 14, 'Ε Προσοχή', { needs_attention: 1 }),
  c(9, 15, 'ΣΤ Επίδειξη', { is_demo: 1, client_account: null, offer_number: null }),
  c(10, 16, 'Ζ Χωρίς payload', { payload: null, due_at: null }),
  c(11, 17, 'Η Σύνδεσμος', { action_url: 'pricelist-clients.html', action_label: 'Άνοιγμα πελάτη', status: 'in_progress',
                             assigned_to: 1, assigned_name: 'Εγώ', ready: false, ready_enforced: true }),
  c(12, 18, 'Θ <b>escape</b> & "x"', { close_reason: '<i>x</i>' }),
];
// Συναρτήσεις που χρειάζονται τα taskRow/render. Όσες δεν υπάρχουν σε μια
// έκδοση του αρχείου απλώς παραλείπονται.
const FUNCS = ['escHtml', 'textOn', 'stBadge', 'whoCell', 'histHtml', 'histWhat', 'canOpen', 'actionBtn', 'summaryHtml',
  'fmtWeekRange', 'taskTitle', 'closedColor', 'closedGroupStyle', 'taskPayload', 'fuelCmsAoa', 'fuelCmsWorkbook',
  'fmtDue', 'athensNow', 'normDateTime', 'isPastDue', 'fmtLeft', 'dueState', 'dueHtml', 'fuelColumns', 'fuelFactsHtml',
  'taskRow', 'render'];
module.exports = { STATUSES, TASKS, FUNCS };
