# Αρίθμηση προσφορών — ο server μοναδική πηγή

Σχέδιο μετάβασης. **Δεν έχει υλοποιηθεί τίποτα από αυτά.**

## Το πρόβλημα

Το PDF τυπώνει τον client-side `offerNum`, το αρχείο κρατά το `offer_ref`
του server. Ήδη απέκλιναν: **0044 στο έγγραφο, 0045 στο filename**.

Αιτία: ο αριθμός παράγεται δύο φορές, από δύο ανεξάρτητες πηγές.
Ο `4a_offer_seq` (ατομικός, server) και η `nextOfferNumber()` (client-side
`max+1` πάνω σε λίστα φιλτραρισμένη κατά `pricelist_scope`).

## Η αρχή

Ο `4a_offer_seq` γίνεται η **μόνη** πηγή. Ο αριθμός δεσμεύεται **πριν**
παραχθεί το PDF, ώστε έγγραφο, filename, email και αρχείο να συμφωνούν.

---

## Έκταση: πού καλείται σήμερα η nextOfferNumber()

Πέντε σημεία, όχι ένα — `frontend/pricelist-clients.html`:

| Γρ. | Καλών | Τι κάνει με τον αριθμό |
|---|---|---|
| 647 | `updateOfferNum()` | **εμφανίζει** πρόβλεψη στη φόρμα (`#offer-num-display`) |
| 1112 | `saveClient()` | **γράφει** στο `4a_clients.offer_number` |
| 1684 | `previewPDF()` | **τυπώνει** στο PDF — 11 σημεία, incl. έντυπο αποδοχής (2106) |
| 2532 | `draftPreview()` | γρήγορο draft μέσω `buildJsPDF()` |
| 2696 | `sendOffer()` | subject + `snapshot.offer.pdf_number` |

Ο ορισμός είναι στη γρ. 581.

---

## ΦΑΣΗ 1 — Βάση (DDL)

```sql
-- 1α. ΠΡΩΤΟ. Βλ. «Τι σπάει», περίπτωση Γ.
ALTER TABLE `4a_clients` DROP INDEX `uq_offer_number`;

-- 1β. ΔΥΟ νέες τιμές, όχι μία
ALTER TABLE `4a_offers` MODIFY `status`
  ENUM('reserved','abandoned','draft','sent','accepted','rejected','superseded','expired')
  NOT NULL DEFAULT 'reserved';

-- 1γ. Το snapshot δεν υπάρχει τη στιγμή του reserve
ALTER TABLE `4a_offers` MODIFY `snapshot` JSON NULL;

-- 1δ. Ξεχωριστό από το created_at, για το TTL της εκκαθάρισης
ALTER TABLE `4a_offers` ADD COLUMN `reserved_at` DATETIME NULL AFTER `created_at`;
```

**Γιατί και `abandoned`:** αν οι εγκαταλελειμμένες διαγράφονταν, θα
ξαναγυρίζαμε στα «κενά χωρίς εξήγηση» που η επιλογή Α θέλει να αποφύγει.
Το `abandoned` απαντά στο «τι έγινε το 0051;» με γραμμή, όχι με σιωπή.

**Σημείο ελέγχου 1**
```sql
SHOW CREATE TABLE `4a_offers`;      -- 8 ENUM values, snapshot json DEFAULT NULL
SHOW INDEX FROM `4a_clients`;       -- ΔΕΝ υπάρχει uq_offer_number
```

---

## ΦΑΣΗ 2 — api/offer_archive.php

### `action=create` (μετασχηματίζεται)

- **Είσοδος:** `client_id`, `client_name`, `client_afm`, `client_email`,
  `country`, `validity_days`, προαιρετικό `revision_of`. **Χωρίς PDF, χωρίς snapshot.**
- **Κάνει:** επικύρωση `country` (υπάρχει ήδη) → `nextOfferNumber($pdo)` ή
  λογική αναθεώρησης → `INSERT` με `status='reserved'`, `snapshot=NULL`,
  `reserved_at=NOW()`.
- **Επιστρέφει:** `{ok, offer_id, offer_ref}` — **χωρίς `log_id`**.
- Η λογική `revision_of` μετακομίζει εδώ: εδώ αποφασίζεται ο αριθμός.

### `action=attach` (νέο)

- **Είσοδος:** `offer_id`, `pdf_base64`, `snapshot`, `fuel_pct`, `to`,
  `subject`, `tariff_version`.
- **Κάνει,** σε transaction:
  `UPDATE 4a_offers SET snapshot=?, fuel_pct=?, status='draft'
   WHERE id=? AND status='reserved'` → **έλεγχος affected rows· αν 0, ματαίωση**.
  Μετά: γράψιμο αρχείου (με unlink σε rollback, όπως τώρα) και
  `INSERT` στο `4a_outbound_log` με `status='pending'`.
- **Επιστρέφει:** `{ok, log_id, offer_ref, sha256}`.

Το `WHERE status='reserved'` δίνει **ιδεμποτεντικότητα δωρεάν**: δεύτερη
κλήση επηρεάζει 0 γραμμές και απορρίπτεται — διπλό κλικ δεν παράγει
δεύτερο αρχείο ούτε δεύτερη εγγραφή log.

### `action=status`

Αμετάβλητο. Το `draft→sent` υπάρχει ήδη.

**Σημείο ελέγχου 2** (curl με έγκυρο token)
```
create  -> {ok, offer_ref}      ; γραμμή 'reserved' με snapshot IS NULL
attach  -> {ok, log_id}         ; γραμμή 'draft', αρχείο στο δίσκο
attach  -> ΞΑΝΑ, ίδιο offer_id  ; ΠΡΕΠΕΙ να απορριφθεί
```

---

## ΦΑΣΗ 3 — Frontend

### `previewPDF(returnBytes=false, forcedRef=null)`

**Μία γραμμή** (1684):
`const offerNum = forcedRef || (…η σημερινή λογική…)`

Τα 11 σημεία χρήσης δεν αγγίζονται — είναι όλα interpolations του ίδιου
local. Πλήρως οπισθοσυμβατό: το κουμπί «Προεπισκόπηση» (γρ. 411) καλεί
`previewPDF()` και συνεχίζει ως έχει, **χωρίς να δεσμεύει αριθμό**.

### `sendOffer()` — νέα σειρά

1. guards (incl. `editingId`)
2. συλλογή + **έλεγχος snapshot** ← παραμένει ΕΔΩ, πριν από κάθε POST
3. `create` → `offerRef`, `offerId`
4. `previewPDF(true, offerRef)`
5. `attach` → `logId`
6. `send_email.php` (subject/filename από `offerRef`)
7. `action=status`

Ο έλεγχος του snapshot μένει **πριν** το `create` — αλλιώς κάθε αποτυχία
επικύρωσης θα έκαιγε αριθμό.

### Υπόλοιπα

- **`updateOfferNum()`** — νέα προσφορά: «θα δοθεί κατά την αποστολή».
  Υπάρχων πελάτης: το αποθηκευμένο `offer_number` ως «τελευταία προσφορά».
- **`saveClient()`** — παύει να παράγει αριθμό. Νέος πελάτης → κενό.
  Ενημερώνεται μόνο από τη `sendOffer()` μετά από επιτυχές `create`.
- **`draftPreview()`** — δεν δεσμεύει· placeholder «ΠΡΟΣΧΕΔΙΟ».
- **`nextOfferNumber()`** — διαγράφεται **τελευταία**, αφού μείνει χωρίς καλούντες.

**Σημείο ελέγχου 3:** μία πραγματική αποστολή· ο αριθμός στο PDF, στο
filename, στο `4a_offers.offer_ref` και στο `4a_outbound_log.reference`
**ταυτίζονται**.

---

## ΦΑΣΗ 4 — Εκκαθάριση

**Lazy sweep μέσα στο `create`**, πριν δεσμεύσει νέο αριθμό:

```sql
UPDATE `4a_offers` SET status='abandoned'
 WHERE status='reserved' AND reserved_at < NOW() - INTERVAL 30 MINUTE;
```

Γιατί έτσι και όχι cron:
- Δεν υπάρχει scheduler στον server (το μόνο cron είναι GitHub Action, που
  δεν βλέπει τη βάση).
- Τρέχει ακριβώς όταν χρειάζεται — όταν κάποιος δεσμεύει αριθμό.
- Ένα UPDATE σε indexed στήλη (`ix_status`), αμελητέο κόστος.

TTL 30 λεπτών: η παραγωγή PDF παίρνει 5–15 δευτ., οπότε δεν υπάρχει
κίνδυνος να σημανθεί ζωντανή προσπάθεια. Και το `attach` έχει
`WHERE status='reserved'`, άρα αν προλάβει το sweep, αποτυγχάνει καθαρά.

### Ορατότητα — μη το παραλείψεις

Το «Ιστορικό αποστολών» δείχνει μόνο `4a_outbound_log`. Οι `reserved` και
`abandoned` **δεν έχουν γραμμή log**, άρα δεν φαίνονται πουθενά. Χρειάζεται
είτε δεύτερη όψη «Δεσμευμένοι αριθμοί χωρίς αποστολή», είτε το `action=list`
να γυρίσει σε `RIGHT JOIN` από το `4a_offers`.

**Χωρίς αυτό η επιλογή Α χάνει το νόημά της:** ο αριθμός δεν λείπει από τη
βάση, αλλά λείπει από τα μάτια σου.

---

## Σειρά εκτέλεσης

```
1. DDL (4 statements)                                        [έλεγχος 1]
2. offer_archive.php: create/attach/sweep → SCP              [έλεγχος 2]
3. frontend: previewPDF, sendOffer, saveClient,
   updateOfferNum, draftPreview, - nextOfferNumber → push    [έλεγχος 3]
4. UI ορατότητας reserved/abandoned                          [έλεγχος 4]
```

---

## Τι σπάει αν σταματήσουμε στη μέση

**Α. Μετά τη Φάση 1 μόνο** → τίποτα. Το DDL είναι καθαρά προσθετικό: νέες
τιμές ENUM που κανείς δεν γράφει, χαλαρότερο `snapshot`, ένα index λιγότερο.
Η σημερινή ροή συνεχίζει αυτούσια. **Ασφαλές σημείο στάσης.**

**Β. Μετά τη Φάση 2, χωρίς τη Φάση 3** → **ΕΠΙΚΙΝΔΥΝΟ.** Το σημερινό
frontend στέλνει `pdf_base64` + `snapshot` στο `create`. Αν το νέο `create`
τα αγνοήσει, κάθε αποστολή θα δημιουργεί γραμμή `reserved` που δεν γίνεται
ποτέ `draft` — **χωρίς αρχείο PDF και χωρίς log**, ενώ τα email συνεχίζουν
να φεύγουν κανονικά. Σιωπηλή απώλεια αρχειοθέτησης.

→ Φάση 2 και 3 ανεβαίνουν **μαζί**, ή το `create` κρατά προσωρινή
συμβατότητα: αν λάβει `pdf_base64`, συμπεριφέρεται όπως σήμερα
(create+attach μονομιάς).

**Γ. Φάση 3 πριν τη Φάση 1** → ο **δεύτερος** νέος πελάτης αποτυγχάνει.
Το frontend σταματά να γράφει `offer_number`, και το `uq_offer_number`
επιτρέπει μόνο **ένα** κενό string. Γι' αυτό το `DROP INDEX` είναι πρώτο.

**Δ. Μέσα στη Φάση 3** → αν φύγει η `nextOfferNumber()` ενώ μένει καλών
(π.χ. `draftPreview`), σκάει `ReferenceError` σε click. Η διαγραφή είναι
τελευταία, μετά από `grep`.

**Ε. Χωρίς τη Φάση 4** → συσσωρεύονται `reserved` για πάντα. Λειτουργικά
δεν χαλάει τίποτα, ο μετρητής προχωρά — αλλά κάθε αποτυχημένη προσπάθεια
αφήνει μόνιμο, αόρατο σκουπίδι.

---

## Δύο αποφάσεις πριν ξεκινήσουμε

1. **Συμβατότητα του `create`:** να κρατήσει legacy μονοπάτι (αν λάβει
   `pdf_base64`, κάνει τα πάντα μονομιάς); **Προτείνεται** — μειώνει
   δραστικά το ρίσκο του παραθύρου μεταξύ SCP και push (περίπτωση Β).
2. **Το `4a_clients.offer_number`:** μένει ως «τελευταία προσφορά», ή
   αποσύρεται αφού το `4a_offers.client_id` κρατά πλέον την πλήρη σχέση;
   Το δεύτερο είναι καθαρότερο, αλλά το πεδίο εμφανίζεται στην κάρτα
   πελάτη (γρ. 1192) και θα χρειαστεί αντικατάσταση από query.

---

## ΦΑΣΗ 3 — λεπτομερές σχέδιο

Κατάσταση: Φάσεις 1 και 2 **ολοκληρωμένες και επιβεβαιωμένες**
(4A-2026-0046 πέρασε από το legacy μονοπάτι, 08-09-2026 15:48).

### 3.1 `previewPDF(returnBytes=false, forcedRef=null)`

Μία γραμμή αλλάζει (γρ. 1684):

```js
const offerNum = forcedRef || (editingId ? … : nextOfferNumber());
```

Τα 11 σημεία χρήσης του `offerNum` μένουν ανέπαφα — είναι όλα interpolations
του ίδιου local. Το κουμπί «Προεπισκόπηση» (γρ. 411) καλεί `previewPDF()`
χωρίς όρισμα και **δεν δεσμεύει αριθμό**.

### 3.2 Νέα ροή `sendOffer()`

```
guards (name / email / pricelists / editingId)
  ↓
συλλογή + ΕΛΕΓΧΟΣ snapshot        ← ΠΡΙΝ από κάθε POST: να μην καεί αριθμός
  ↓
create (χωρίς PDF)     → offerId, offerRef
  ↓
previewPDF(true, offerRef)         ← ο αριθμός τυπώνεται ΜΕΣΑ στο PDF
  ↓
attach (PDF + snapshot) → logId
  ↓
send_email (subject + filename από offerRef)
  ↓
status
```

Το `snapshot` μετακομίζει από το `create` στο `attach`. Το
`snapshot.offer.pdf_number` γίνεται **ίδιο** με το `offer_ref` — η απόκλιση
0044/0045 δεν μπορεί πλέον να συμβεί εξ ορισμού.

### 3.3 Χειρισμός 409

Το `attach` γυρίζει 409 όταν η γραμμή δεν είναι `reserved`. Τρεις
περιπτώσεις, **δύο** αντιδράσεις:

| Κατάσταση | Τι σημαίνει | Αντίδραση |
|---|---|---|
| `abandoned` | πέρασαν 30′, το sweep την πρόλαβε | **αυτόματη νέα δέσμευση**, ΜΙΑ φορά |
| `draft` | το attach είχε ήδη πετύχει (διπλό κλικ) | ΟΧΙ retry — ενημέρωση χρήστη |
| `sent` | έχει ήδη σταλεί | ΟΧΙ retry |

Ο βρόχος:

```
attach -> 409 & abandoned
   ↓
create ξανά            → νέο offerRef   (ο παλιός μένει 'abandoned')
   ↓
previewPDF(true, νέο)  ← ΥΠΟΧΡΕΩΤΙΚΗ αναπαραγωγή PDF
   ↓
attach -> επιτυχία ή οριστική αποτυχία (καμία δεύτερη επανάληψη)
```

**Το κρίσιμο:** το PDF ΠΡΕΠΕΙ να ξαναπαραχθεί. Δεν αρκεί νέο attach — ο
αριθμός είναι τυπωμένος μέσα στο έγγραφο και στο έντυπο αποδοχής (γρ. 2106).
Κόστος +5-15 δευτ., με ένδειξη στο κουμπί.

**Απαιτεί μικρή αλλαγή στο endpoint (βήμα 3α):** σήμερα το 409 επιστρέφει
μόνο ελληνικό κείμενο («…είναι: abandoned»). Το parsing κειμένου είναι
εύθραυστο. Προσθήκη μηχαναγνώσιμου πεδίου:

```json
{"ok": false, "error": "…", "offer_status": "abandoned"}
```

Δύο γραμμές στο `handleAttach()` — ένα ακόμη SCP πριν από το push.

### 3.4 Το badge στην κάρτα πελάτη (γρ. 1192)

```js
<span class="offer-badge">${c.offer_number||'—'}</span>
```

**Ο κώδικας δεν αλλάζει· η σημασία αλλάζει.**

| | Σήμερα | Μετά τη Φάση 3 |
|---|---|---|
| Πηγή | `nextOfferNumber()` client-side, στο save | `offer_ref` του server |
| Σημασία | «ο αριθμός προσφοράς του πελάτη» | «η **τελευταία** προσφορά» |
| Νέος πελάτης | πάντα έχει αριθμό (44/44) | **`—` μέχρι την πρώτη αποστολή** |
| Αναθεώρηση | δεν υπήρχε | εμφανίζει `4A-2026-0046-R1` (χωρά σε varchar(20)) |

Τρεις επιλογές:

- **Α. Ως έχει + tooltip** «τελευταία προσφορά». Ελάχιστη αλλαγή, σωστή σημασία.
- **Β. Fallback στο `offersByClient`** όταν το `offer_number` είναι κενό —
  το «Ιστορικό αποστολών» έχει ήδη τα δεδομένα φορτωμένα. Χάνεται όμως
  για όποιον δεν έχει `offers:view`.
- **Γ. Κατάργηση** — η μίνι-λίστα δείχνει ήδη τις πραγματικές αποστολές.

**Προτείνεται Α**, με το Β ως fallback μόνο όταν το πεδίο είναι κενό.

### 3.5 Πού γράφεται πλέον το `4a_clients.offer_number`

Η `nextOfferNumber()` φεύγει από τη `saveClient()` (γρ. 1112). Νέος πελάτης
→ κενό. Η ενημέρωση γίνεται **server-side**, μία γραμμή μέσα στο `create`:

```sql
UPDATE `4a_clients` SET offer_number = ? WHERE id = ?
```

Ατομικό, στο ίδιο transaction, χωρίς δεύτερο POST από τον browser που θα
ξαναέγραφε ολόκληρο τον πελάτη με ό,τι έχει η φόρμα εκείνη τη στιγμή.
Το `uq_offer_number` έχει ήδη πέσει (Φάση 1), οπότε καμία σύγκρουση.

### 3.6 Υπόλοιπα

- **`updateOfferNum()`** (647) — νέα προσφορά: «θα δοθεί κατά την αποστολή».
  Υπάρχων πελάτης: το αποθηκευμένο `offer_number` με ετικέτα «τελευταία».
- **`draftPreview()`** (2532) — placeholder «ΠΡΟΣΧΕΔΙΟ», καμία δέσμευση.
- **`nextOfferNumber()`** (581) — διαγράφεται ΤΕΛΕΥΤΑΙΑ, μετά από `grep`
  που επιβεβαιώνει μηδέν καλούντες.

### Σειρά και σημεία ελέγχου

```
3α. offer_archive.php: +offer_status στο 409     → SCP   [409 γυρίζει το πεδίο]
3β. frontend: previewPDF, sendOffer, saveClient,
    updateOfferNum, draftPreview, −nextOfferNumber → push [αποστολή 0047]
3γ. επαλήθευση: ίδιος αριθμός σε PDF / filename /
    4a_offers.offer_ref / 4a_outbound_log.reference
3δ. δοκιμή abandoned: χειροκίνητο UPDATE σε 'abandoned',
    μετά attach → πρέπει να ξαναδεσμεύσει αυτόματα
```

### Τι σπάει αν σταματήσουμε στη μέση

- **Μετά το 3α μόνο** → τίποτα. Προσθήκη πεδίου που κανείς δεν διαβάζει ακόμη.
  Το legacy μονοπάτι κρατά το παλιό frontend ζωντανό. **Ασφαλές σημείο στάσης.**
- **Μετά το 3β** → rollback = `git revert` + push. Ο server δεν χρειάζεται
  αλλαγή: το `create` δέχεται ΚΑΙ τις δύο μορφές.
- **Μη αναστρέψιμο:** οι αριθμοί που καίγονται σε αποτυχίες μεταξύ `create`
  και `attach`. Εξ ορισμού της επιλογής Α, και ορατοί ως `abandoned`.

### Εκκρεμεί μετά τη Φάση 3

1. **Φάση 5** — αφαίρεση του legacy κλάδου από το `create`, αφού
   επιβεβαιωθεί ότι κανείς δεν στέλνει πια `pdf_base64` εκεί.
2. **Ορατότητα** `reserved`/`abandoned` στο «Ιστορικό αποστολών» — σήμερα
   δείχνει μόνο `4a_outbound_log`, όπου αυτές δεν έχουν γραμμή.

---

## Σχετικά

- `docs/snapshot_spec.md` — τι πρέπει να περιέχει το snapshot
- `migrations/001_offers_outbound.sql` — το αρχικό σχήμα
- `api/offer_archive.php` — το endpoint όπως είναι σήμερα
