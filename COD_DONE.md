# COD — κατάσταση εργασιών

Τελευταία ενημέρωση: **2026-07-10**

---

## Ολοκληρωμένα (live & επιβεβαιωμένα)

| Commit | Τι |
|---|---|
| `31654f2` | **COD persistence** — το `clients.php` διάβαζε το `4a_clients.cod` με `SELECT *`, αλλά κανένα από τα δύο INSERT δεν το έγραφε ποτέ. Προστέθηκε στα INSERT, στο `ON DUPLICATE KEY UPDATE` και στο decode loop του GET. |
| `8afe412` | **Δυναμικό COD doc** — render του `4A-EXPLAIN-003` ανά πελάτη μέσα στο merged offer PDF. |
| `5ec50cb` | **Race fix** — δες παρακάτω. |
| `a6ee9db` | **Migration 007** — `4a_clients.cod` (`JSON NULL`). Idempotent· η στήλη υπήρχε ήδη live (προστέθηκε χειροκίνητα), οπότε επιστρέφει `added:false`. Υπάρχει ώστε ένα καθαρό deploy να φτιάχνει το ίδιο schema. |
| `98818dc` | **gitignore `*_LIVE.php`** — το `clients_LIVE.php` καθόταν untracked στο root και έμοιαζε με repo source ενώ ήταν παλιό pull από τον server. |
| `79012ac` | **`saveClient` δεν καταπίνει σφάλματα** — έλεγχος `r.ok`, μη-JSON σώματος (PHP fatal σε HTML), και `{ok:false}`. |

### Το race condition (`5ec50cb`) — η ουσία

Άνοιγμα πελάτη επανέφερε τα COD `flat_fee`/`tier_pct` στα global defaults (1.30 / 0.50) παρόλο που η βάση είχε τις σωστές τιμές — και μετά το PDF τύπωνε τα defaults.

Το `editClient()` καλεί `renderPlTags()` δύο φορές (η δεύτερη ζωγραφίζει το 💰 badge, αφού αλλάξει το `codEnabled`). Κάθε κλήση πυροδοτεί `checkCodVisibility()`, που κάνει `await` στο `charge_limits`. Η **πρώτη** ξεκινούσε *πριν* γεμίσει το `pendingCodSnapshot` και τερμάτιζε *μετά* — άρα έγραφε defaults πάνω από τις σωστές τιμές που είχε μόλις γράψει η δεύτερη.

Λύση: monotonic `codReqSeq` στο `checkCodVisibility()` — ένα request που δεν είναι πια το νεότερο εγκαταλείπει μετά το `await` αντί να αγγίξει το DOM. Επιπλέον το `pendingCodSnapshot` έπαψε να είναι one-shot: επιβιώνει τα re-render, καθαρίζει μόνο στο `resetCod()` (δεν διαρρέει στον επόμενο πελάτη), και συγχρονίζεται με ό,τι πληκτρολογεί ο χρήστης μέσω `syncCodSnapshotFromInputs()`.

Το PDF δεν χρειάστηκε αλλαγή: το `codPayload` διαβάζει τα live inputs εκ σχεδιασμού, άρα τύπωνε πιστά τιμές που το race είχε ήδη αλλοιώσει.

---

## Εκκρεμότητες

### 1. Θέση του COD μετά τον τελευταίο τιμοκατάλογο
Το COD section πρέπει να εμφανίζεται μετά τον τελευταίο τιμοκατάλογο στην προσφορά.
*Δεν έχει διερευνηθεί.*

### 2. `migrate_pdf_urls.php` — νάρκη
⚠️ **Δεν υπάρχει στο repo ούτε στο git history.** Αν υπάρχει, ζει μόνο στον live server —
όπως ακριβώς το `clients_LIVE.php` και όπως η στήλη `cod` που είχε προστεθεί χειροκίνητα.
Πριν οτιδήποτε άλλο: εντόπισέ το στον server και δες τι κάνει. Ένα migration που δεν
υπάρχει στο repo δεν μπορεί να ελεγχθεί ούτε να αναπαραχθεί σε καθαρό deploy.

### 3. Security — token rotation
*Δεν έχει διερευνηθεί σε αυτή τη συνεδρία.* Σημείωση: το `api/deploy.php` έχει το
webhook secret ως literal (`$secret = 'WEBHOOK_SECRET'`) — αξίζει έλεγχος αν αυτή
είναι placeholder τιμή ή η πραγματική.

### 4. Cosmetic — «Ποσό: €0.00» στο cover summary
Το cover summary του COD doc τυπώνει `€0.00` αντί για το ποσό αντικαταβολής.
Υποψία (μη επιβεβαιωμένη): το `codPayload` στέλνει `flat_fee`, `tier_pct`, `limit`,
`services`, `date` — **όχι** `cod_amount`. Ξεκίνα από εκεί και δες τι περιμένει
το `generate_html_pdf.php`.

### 5. `sync` DELETE χωρίς transaction
`api/clients.php:89` — το `action=sync` κάνει `DELETE FROM 4a_clients` και μετά
INSERT loop, **χωρίς transaction**. Αν σκάσει ένα INSERT στη μέση, ο πίνακας πελατών
μένει μερικώς άδειος και δεν υπάρχει rollback. Θέλει `beginTransaction()`/`commit()`
με `rollBack()` στο catch.

### 6. Τεστ του `saveClient` retry flow
Το `79012ac` εισήγαγε retry path που **δεν υπήρχε** πριν: σε αποτυχία η φόρμα μένει
ανοιχτή αντί να καθαρίζει. Καρφώνεται το `editingId` στην τοπική εγγραφή, αλλιώς
δεύτερο πάτημα σε *νέο* πελάτη θα δημιουργούσε διπλή εγγραφή με νέο αριθμό προσφοράς.
Η λογική δεν έχει δοκιμαστεί με πραγματική αποτυχία server.

Επίσης: το `apiFetch` (`pricelist-clients.html:444`) ήδη κάνει alert + `location.reload()`
σε 401. Ένα ληγμένο session θα δείξει τώρα **δύο** alerts. Θέλει απόφαση για το ποιος
χειρίζεται το 401.
