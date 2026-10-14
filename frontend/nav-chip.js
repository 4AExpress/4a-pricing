/* nav-chip.js | v1.0 | 14-10-2026
 *
 * Chip συνδέσμου προς άλλη σελίδα: «🔗 + εικονίδιο της σελίδας-προορισμού
 * + λέξη», π.χ. «🔗👥 Πελάτης» (tasks.html) και «🔗✅ Εργασίες»
 * (pricelist-clients.html). Κοινό για τις δύο σελίδες, ώστε ο κανόνας να
 * ζει σε ΕΝΑ σημείο.
 *
 * ΤΟ ΕΙΚΟΝΙΔΙΟ ΔΕΝ ΓΡΑΦΕΤΑΙ ΕΔΩ. Διαβάζεται από το link του μενού
 * (a.nav-btn) που δείχνει στη σελίδα-προορισμό:
 *   · αντιστοίχιση με το ΟΝΟΜΑ ΑΡΧΕΙΟΥ του href (τελευταίο τμήμα του
 *     pathname), άρα «./tasks.html» και «tasks.html?x=1» = «tasks.html»
 *   · από τον ΠΡΩΤΟ κόμβο κειμένου του link (το tasks-badge.js προσθέτει
 *     <span> με αριθμούς ΜΕΤΑ από αυτόν), η πρώτη «λέξη»
 *   · δεκτό ΜΟΝΟ αν περιέχει emoji (Extended_Pictographic). Το «+» του
 *     «+ Νέος Τιμοκατάλογος» δεν είναι εικονίδιο.
 * Αν δεν βρεθεί: «🔗 + λέξη», χωρίς σφάλμα.
 *
 * Οι λέξεις, τα titles και το 🔗 τα δίνει η σελίδα (UI_TEMP στο tasks.html,
 * σταθερές στην κορυφή του pricelist-clients.html) -> 4a_ui_texts με το Κ4α.
 */
var NavChip = (function () {
  var PICTO = /\p{Extended_Pictographic}/u;

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /** Όνομα αρχείου του href: «./a/tasks.html?x=1#y» -> «tasks.html». */
  function fileOf(href, base) {
    try {
      var p = new URL(String(href), base || 'https://x.invalid/').pathname;
      return p.slice(p.lastIndexOf('/') + 1);
    } catch (e) { return ''; }
  }

  /** Το εικονίδιο του μενού για το αρχείο $file, ή '' αν δεν υπάρχει. */
  function navIcon(doc, file) {
    try {
      var base = (doc && doc.baseURI) || 'https://x.invalid/';
      var links = doc.querySelectorAll('a.nav-btn[href]');
      for (var i = 0; i < links.length; i++) {
        var a = links[i];
        if (fileOf(a.getAttribute('href'), base) !== file) continue;
        var n = a.firstChild;
        while (n && n.nodeType !== 3) n = n.nextSibling;
        var tok = n ? String(n.nodeValue || '').trim().split(/\s+/)[0] : '';
        return tok && PICTO.test(tok) ? tok : '';
      }
    } catch (e) { /* χωρίς μενού: χωρίς εικονίδιο */ }
    return '';
  }

  /**
   * Το chip. o = { doc, target: 'tasks.html', href, link: '🔗', word, title }
   * Το href το έχει ήδη επικυρώσει η σελίδα· εδώ μόνο escaping.
   */
  function html(o) {
    var icon = navIcon(o.doc, o.target);
    return '<a class="nav-chip" href="' + esc(o.href) + '" title="' + esc(o.title) + '">' +
           esc((o.link || '') + icon + ' ' + (o.word || '')) + '</a>';
  }

  return { fileOf: fileOf, navIcon: navIcon, html: html };
})();
