/* =====================================================================
 *  4a-pricing · shelf-grouping.js
 *  Κοινή λογική ομαδοποίησης ραφιού: χώρα → mode → sort_order.
 *
 *  ΕΝΣΩΜΑΤΩΣΗ: ΠΡΙΝ από το inline <script> κάθε σελίδας — όχι πριν το
 *  </body> όπως το announce.js. Οι σελίδες καλούν init() στην τελευταία
 *  γραμμή του inline block, οπότε τα σύμβολα πρέπει να υπάρχουν ήδη:
 *      <script src="shelf-grouping.js?v=2026-09-22"></script>
 *  Το ?v= ανεβαίνει σε κάθε αλλαγή αυτού του αρχείου — χωρίς αυτό ο
 *  browser κρατά το παλιό js δίπλα σε νέο html.
 *
 *  ΑΡΧΕΣ
 *   1. Χώρα και mode ΜΟΝΟ από 4a_services.country / .type — ποτέ από
 *      κατάληξη κωδικού (_GR, _CY) ή από hardcoded λίστα.
 *   2. Τίποτα δεν κρύβεται σιωπηλά: άγνωστη χώρα ή τύπος εκτός λίστας
 *      πάνε σε ορατούς κουβάδες με ⚠, δεν εξαφανίζονται.
 *   3. Χωρίς DOM. Δεδομένα μέσα, δεδομένα έξω. Κάθε σελίδα κρατά το δικό
 *      της φίλτρο ορατότητας (ο editor το scope του server, η σελίδα
 *      πελατών τις χώρες του χρήστη) και το δικό της render.
 * ===================================================================== */
(function () {
  if (window.shelfGroupTree) return;   // φορτώθηκε ήδη — μη ξαναορίσεις τίποτα

  const SHELF_BLOCKS = [
    { key: 'GR', cls: 'gr', title: '🇬🇷 ΕΛΛΑΔΑ' },
    { key: 'CY', cls: 'cy', title: '🇨🇾 ΚΥΠΡΟΣ' },
  ];

  // Η σειρά εδώ είναι η σειρά εμφάνισης. Mode χωρίς services δεν βγαίνει.
  const SHELF_MODES = [
    { key: 'AIR',   title: '✈️ ΑΕΡΟΠΟΡΙΚΑ' },
    { key: 'ROAD',  title: '🚛 ΟΔΙΚΑ' },
    { key: 'SEA',   title: '🚢 ΝΑΥΤΙΛΙΑΚΑ' },
    { key: 'COMBI', title: '🔀 COMBI' },
  ];

  // Αρχικό run από pictographs / σημαίες / βέλη / VS16 / ZWJ. Χρειάζεται
  // επειδή το 4a_services.name κουβαλά εμοτζί μπροστά σε 10 από τα 12 rows
  // (από το seed του services.php), ενώ η στήλη emoji κρατά το δικό της.
  const SHELF_NAME_LEAD = /^[\p{Extended_Pictographic}\p{Regional_Indicator}←-⇿⬀-⯿️‍\s]+/u;

  function svcStripLead(name) {
    return String(name == null ? '' : name).replace(SHELF_NAME_LEAD, '').trim();
  }

  // rows: πίνακας γραμμών 4a_services (code, country, type, sort_order).
  // Επιστρέφει [{ key, cls, title, modes:[{ key, title, svcs:[row] }] }].
  function shelfGroupTree(rows) {
    const list   = (rows || []).filter(Boolean);
    const bySort = (a, b) => ((+a.sort_order || 0) - (+b.sort_order || 0))
                             || String(a.code).localeCompare(String(b.code));
    const modeOf = r => String(r.type || '').trim().toUpperCase();
    const known  = r => SHELF_BLOCKS.some(bl => bl.key === r.country);

    const blocks = SHELF_BLOCKS.map(bl => {
      const svcs  = list.filter(r => r.country === bl.key);
      const modes = SHELF_MODES
        .map(m => ({ key: m.key, title: m.title, svcs: svcs.filter(r => modeOf(r) === m.key).sort(bySort) }))
        .filter(m => m.svcs.length);
      // Τύπος εκτός λίστας ή κενός: χωριστά, όχι σιωπηλή απόκρυψη.
      const rest = svcs.filter(r => !SHELF_MODES.some(m => m.key === modeOf(r))).sort(bySort);
      if (rest.length) modes.push({ key: '?', title: '⚠ MODE ΑΓΝΩΣΤΟ', svcs: rest });
      return { key: bl.key, cls: bl.cls, title: bl.title, modes: modes };
    }).filter(bl => bl.modes.length);

    // Χωρίς γραμμή στο 4a_services ή με άγνωστη χώρα: ορατά, όχι μαντεψιά.
    const orphan = list.filter(r => !known(r)).sort(bySort);
    if (orphan.length) blocks.push({
      key: '?', cls: '', title: '⚠ ΧΩΡΑ ΑΓΝΩΣΤΗ',
      modes: [{ key: '?', title: '', svcs: orphan }]
    });
    return blocks;
  }

  window.SHELF_BLOCKS    = SHELF_BLOCKS;
  window.SHELF_MODES     = SHELF_MODES;
  window.SHELF_NAME_LEAD = SHELF_NAME_LEAD;
  window.svcStripLead    = svcStripLead;
  window.shelfGroupTree  = shelfGroupTree;
})();
