// ============================================================================
// Αντικατάσταση της sendOffer() — frontend/pricelist-clients.html (~γρ. 2486)
// ----------------------------------------------------------------------------
// Αλλαγή ροής: αρχειοθέτηση ΠΡΙΝ την αποστολή.
//
//   ΠΡΙΝ:  PDF → send_email.php → alert()
//   ΤΩΡΑ:  PDF → offer_archive.php → send_email.php → status update
//
// Έτσι: (α) το αρχείο υπάρχει ακόμη κι αν σκάσει το SMTP,
//       (β) ξέρεις και τις αποτυχημένες προσπάθειες,
//       (γ) ο αριθμός προσφοράς παράγεται στον server (ατομικά).
// ============================================================================

async function sendOffer() {
  const btn = document.getElementById('send-btn');
  const email = document.getElementById('cl-email').value.trim();   // ΕΛΕΓΞΕ το id
  const name  = document.getElementById('cl-name').value.trim();    // ΕΛΕΓΞΕ το id

  if (!email) { alert('Λείπει το email του πελάτη.'); return; }

  btn.textContent = 'Αποστολή...';
  btn.disabled = true;

  let logId = null;

  try {
    // ── 1. Παραγωγή PDF ─────────────────────────────────────────────────────
    const finalBytes = await previewPDF(true);
    let binary = '';
    for (let i = 0; i < finalBytes.length; i++) binary += String.fromCharCode(finalBytes[i]);
    const pdf_base64 = btoa(binary);

    // ── 2. Snapshot — ΟΛΑ όσα χρειάζονται για αναπαραγωγή ───────────────────
    // ΚΡΙΣΙΜΟ: ό,τι λείπει από εδώ θα ξαναϋπολογιστεί από ΤΡΕΧΟΝΤΑ δεδομένα
    // όταν ανοίξει η προσφορά αργότερα. Σήμερα λείπουν fuel και ζώνες Z2+,
    // γι' αυτό μια προσφορά του Ιουνίου δείχνει σημερινά νούμερα.
    const snapshot = {
      pricelists: selectedPls,          // ΕΛΕΓΞΕ το όνομα της μεταβλητής
      surcharges: currentSurcharges,    // ΕΛΕΓΞΕ
      cod:        pendingCodSnapshot,   // ΕΛΕΓΞΕ
      fuel:       currentFuelData,      // ΕΛΕΓΞΕ — πρέπει να είναι η ΤΡΕΧΟΥΣΑ τιμή
      zones_all:  true,                 // όλες οι ζώνες, όχι μόνο Z1
      captured_at: new Date().toISOString()
    };

    // ── 3. Αρχειοθέτηση (πριν το SMTP) ──────────────────────────────────────
    const arc = await apiFetch(`${API}/offer_archive.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        client_id:    editingId || null,
        client_name:  name,
        client_email: email,
        client_afm:   document.getElementById('cl-afm')?.value || null,
        country:      document.getElementById('cl-country')?.value || 'GR',
        to:           email,
        subject:      '',                 // συμπληρώνεται παρακάτω
        filename:     '',                 // συμπληρώνεται παρακάτω
        snapshot,
        fuel_pct:      currentFuelPct || null,   // ΕΛΕΓΞΕ
        tariff_version: TARIFF_VERSION || null,  // ΕΛΕΓΞΕ αν υπάρχει
        validity_days: parseInt(document.getElementById('cl-validity')?.value) || 30,
        revision_of:   currentOfferId || null    // για αναθεώρηση
      })
    });

    const arcData = await arc.json();
    if (!arcData.ok) {
      alert('Σφάλμα αρχειοθέτησης: ' + (arcData.error || 'Άγνωστο')
            + '\n\nΗ προσφορά ΔΕΝ στάλθηκε.');
      return;
    }

    logId = arcData.log_id;
    const offerRef = arcData.offer_ref;

    // ── 4. Αποστολή ─────────────────────────────────────────────────────────
    const r = await apiFetch(`${API}/send_email.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        to: email,
        subject: `Προσφορά 4A Express — ${offerRef}`,
        body: `Αγαπητέ/ή ${name},\n\nΣας αποστέλλουμε την προσφορά μας.\n\n`
            + `Με εκτίμηση,\nΗ ομάδα 4A Express`,
        pdf_base64,
        filename: `Προσφορά_4A_Express_${offerRef}.pdf`
      })
    });
    const d = await r.json();

    // ── 5. Ενημέρωση κατάστασης ─────────────────────────────────────────────
    await apiFetch(`${API}/offer_archive.php?action=status`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        log_id:  logId,
        success: !!d.ok,
        error:   d.ok ? null : (d.error || 'Άγνωστο σφάλμα SMTP')
      })
    });

    if (d.ok) {
      alert(`Η προσφορά ${offerRef} στάλθηκε επιτυχώς!`);
      if (typeof loadClients === 'function') loadClients();   // ανανέωση λίστας
    } else {
      alert('Σφάλμα αποστολής: ' + (d.error || 'Άγνωστο')
            + `\n\nΗ προσφορά ${offerRef} αρχειοθετήθηκε και μπορεί να σταλεί ξανά.`);
    }

  } catch (e) {
    // Αν έχει ήδη δημιουργηθεί εγγραφή, τη σημειώνουμε ως failed
    if (logId) {
      try {
        await apiFetch(`${API}/offer_archive.php?action=status`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ log_id: logId, success: false, error: e.message })
        });
      } catch (_) { /* σιωπηλά — το κύριο σφάλμα φαίνεται παρακάτω */ }
    }
    alert('Σφάλμα: ' + e.message);

  } finally {
    btn.textContent = '✉️ Αποστολή Προσφοράς';
    btn.disabled = false;
  }
}
