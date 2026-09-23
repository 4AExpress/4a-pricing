# Σύστημα εργασιών — Φάση 2: παραγωγή εργασιών

**Κατάσταση:** προδιαγραφή. Καμία γραμμή κώδικα δεν έχει γραφτεί.
**Ημερομηνία:** 23/09/2026
**Προϋποθέσεις:** `db/migrations/2026-09-23_tasks_foundation.sql` (`6332447`)
και `2026-09-23b_tasks_offer_number.sql` (`ebded61`) — και τα δύο έχουν
τρέξει στην παραγωγή.

> Δημόσια εκδοχή. Ονόματα πελατών και διευθύνσεις δεν μπαίνουν εδώ.

---

## 1. Πού μπαίνει ο έλεγχος μετάβασης

Το `api/clients.php` είναι το **μοναδικό** σημείο εγγραφής πελάτη. Δεν
υπάρχει `action='set_status'` — το status ταξιδεύει μέσα στο πλήρες
σώμα του `save`, και ο server σήμερα το γράφει χωρίς να κοιτάξει την
προηγούμενη τιμή.

Η τρέχουσα δομή:

| Γραμμή | Τι κάνει |
|---|---|
| `api/clients.php:9` | `require_permission('pricelist-clients','edit')` |
| `api/clients.php:43` | `$action = $b['action'] ?? 'save'` |
| `api/clients.php:45` | `if ($action === 'save') {` |
| `api/clients.php:52` | επικύρωση `country` — η μόνη που υπάρχει |
| `api/clients.php:56-72` | `prepare` του `INSERT ... ON DUPLICATE KEY UPDATE` |
| `api/clients.php:73-87` | `execute` |
| `api/clients.php:88` | `respond(['ok' => true])` |

**Τα τρία σημεία παρέμβασης:**

1. **Πριν τη γραμμή 56** — ανάγνωση της παλιάς κατάστασης. Πρέπει να
   γίνει πριν το UPSERT, αλλιώς η παλιά τιμή έχει ήδη χαθεί:
   ```
   SELECT status FROM 4a_clients WHERE id = ?     -> $oldStatus (NULL αν νέος)
   ```

2. **Γύρω από 56-87** — `beginTransaction()` πριν, `commit()` μετά.

3. **Ανάμεσα στη 87 και τη 88** — η κλήση, μέσα στην ίδια συναλλαγή:
   ```
   if ($oldStatus !== 'accepted' && $newStatus === 'accepted') {
       createTasksForClient($db, (int)$b['id']);
   }
   ```

Η συνθήκη είναι **μετάβαση**, όχι κατάσταση. Σκέτο `$newStatus ===
'accepted'` θα παρήγαγε προσπάθεια δημιουργίας σε κάθε αποθήκευση ενός
ήδη αποδεκτού πελάτη — θα την έπιανε το UNIQUE, αλλά με άσκοπο θόρυβο
και σφάλματα στα logs.

### Δύο διορθώσεις που αξίζει να γίνουν στην ίδια κίνηση

- **Το `status` είναι `varchar(30)` χωρίς περιορισμό.** Το frontend
  στέλνει μία από πέντε τιμές (`pricelist-clients.html:362-366`), αλλά
  ένα typo γράφεται αυτούσιο στη βάση. Το `4a_offers.status` είναι
  σωστό `enum` — το `4a_clients.status` πρέπει να γίνει το ίδιο, ή
  τουλάχιστον να επικυρώνεται δίπλα στο `country` (γραμμή 52).
- **Οι τιμές `rejected` και `active` δεν χρησιμοποιούνται ποτέ**
  (έλεγχος παραγωγής 23/09: `accepted` 21, `prospect` 16, `negotiate`
  9, τα άλλα δύο μηδέν). Το badge «↻ Ανανέωση»
  (`pricelist-clients.html:1494,1500`) εξαρτάται από αυτά και άρα δεν
  εμφανίζεται ποτέ. Θέλει απόφαση: ή χρησιμοποιούνται, ή φεύγουν.

---

## 2. `createTasksForClient()` — ψευδοκώδικας

```
function createTasksForClient($db, $clientId):

    # ---- 1. Ο πελάτης, όπως ΜΟΛΙΣ γράφτηκε -------------------------
    # Διαβάζουμε από τη ΒΑΣΗ, όχι από το $b του αιτήματος: θέλουμε ό,τι
    # πραγματικά αποθηκεύτηκε, με το JSON ήδη κανονικοποιημένο από τη MySQL.
    client = SELECT country, offer_number, cod, pricelists
               FROM 4a_clients WHERE id = $clientId

    # ---- 2. Κανονικοποίηση αριθμού προσφοράς -----------------------
    # ΚΡΙΣΙΜΟ. Το offer_number μπαίνει στο UNIQUE. Αν το '—' και το ''
    # περάσουν ως διαφορετικές τιμές, ο ίδιος πελάτης παίρνει δεύτερο
    # σετ εργασιών. Το em-dash υπάρχει ήδη σε 26 από 46 πελάτες στη
    # στήλη `account` — η ίδια φόρμα το παράγει.
    offerNo = trim(client.offer_number ?? '')
    if offerNo == '—':  offerNo = ''          # U+2014

    # ---- 3. Οι σημαίες για τα condition_key ------------------------
    # has_cod:  4a_clients.cod (JSON) -> $.cod_enabled === true
    #           γράφεται στο pricelist-clients.html:1370
    #           διαβάζεται ήδη σε :1445, :1510, :1516, :1779
    hasCod  = (client.cod IS NOT NULL) AND (client.cod->'$.cod_enabled' == true)

    # has_fuel: 4a_clients.pricelists (JSON array) -> ΕΣΤΩ ΕΝΑ
    #           στοιχείο με fuel_enabled == 1
    #           γράφεται στο pricelist-clients.html:1368
    #           ΠΡΟΣΟΧΗ: το fuel είναι ανά ΤΙΜΟΚΑΤΑΛΟΓΟ, όχι ανά πελάτη.
    #           Απόφαση 23/09: ΜΙΑ εργασία cms_fuel ανά πελάτη, γιατί
    #           παράγεται ΕΝΑ αρχείο εισαγωγής. Ποιοι τιμοκατάλογοι
    #           αφορά -> μέσα στο payload.
    hasFuel = ANY(pl.fuel_enabled == 1 for pl in client.pricelists)

    # ---- 4. Οι τύποι, με τη σειρά τους -----------------------------
    types = SELECT code, condition_key, depends_on, sla_hours
              FROM 4a_task_types WHERE active = 1 ORDER BY sort_order

    # ---- 5. Δημιουργία, σε μία συναλλαγή ---------------------------
    created = []
    for t in types:

        # φίλτρο condition_key: NULL = πάντα
        if t.condition_key == 'has_cod'  and not hasCod:   continue
        if t.condition_key == 'has_fuel' and not hasFuel:  continue

        # payload: τι χρειάζεται η εργασία για να εκτελεστεί
        payload = { account: client.account }
        if t.code == 'cms_fuel':
            payload.services = [pl.service_id for pl in client.pricelists
                                              if pl.fuel_enabled == 1]

        # INSERT IGNORE: η μοναδικότητα επιβάλλεται από τη ΒΑΣΗ, όχι από
        # έλεγχο πριν το insert. Ένα SELECT-then-INSERT θα περνούσε δύο
        # φορές με ταυτόχρονα αιτήματα.
        rows = INSERT IGNORE INTO 4a_tasks
                 (client_id, task_code, offer_number, status, payload)
               VALUES ($clientId, t.code, offerNo, 'open', payload)

        if rows == 0:  continue        # υπήρχε ήδη — σιωπηλά, όχι σφάλμα

        taskId = LAST_INSERT_ID()
        created.push(taskId)

        INSERT INTO 4a_task_events (task_id, event, actor_id, note)
        VALUES (taskId, 'created', $session.id,
                'αυτόματη δημιουργία από μετάβαση σε accepted')

    # ---- 6. Ανάθεση ------------------------------------------------
    # Απόφαση 23/09: ΚΑΜΙΑ αυτόματη επιλογή αναδόχου.
    # Κάθε εργασία μένει assigned_to = NULL και την αναλαμβάνει όποιος
    # θέλει από την ουρά. Ο κανόνας επιλογής έρχεται αργότερα.
    #
    # Το 4a_user_task_skills ΔΕΝ χρησιμοποιείται ακόμα για ανάθεση —
    # μόνο για να φιλτράρει ΠΟΙΟΣ ΒΛΕΠΕΙ τι στην ουρά:
    #     SELECT * FROM 4a_tasks t
    #      WHERE t.assigned_to IS NULL AND t.status = 'open'
    #        AND EXISTS (SELECT 1 FROM 4a_user_task_skills s
    #                     WHERE s.user_id = :me
    #                       AND s.task_code = t.task_code
    #                       AND s.country IN (:clientCountry, 'BOTH'))
    #
    # Η ουρά είναι ΕΡΩΤΗΜΑ, όχι πίνακας. Το ix_assigned_status
    # (assigned_to, status) την καλύπτει ήδη.

    return created
```

### Συναλλαγή

Το UPSERT του πελάτη και η δημιουργία εργασιών είναι **μία** συναλλαγή.
Αν σκάσει η δεύτερη, δεν θέλουμε πελάτη σε `accepted` χωρίς εργασίες —
γιατί η μετάβαση δεν θα ξανασυμβεί ποτέ και οι εργασίες δεν θα
δημιουργηθούν ούτε με δεύτερη αποθήκευση.

### Τι δεν κάνουμε

Δεν υπολογίζουμε `due_at` σε αυτή τη φάση. Όλα τα `sla_hours` είναι
`NULL` στο seed, οπότε δεν υπάρχει τίποτα να υπολογιστεί.

---

## 3. Το `depends_on` στην οθόνη

**Πρόταση: δημιουργούνται ΟΛΕΣ αμέσως, οι εξαρτημένες κλειδωμένες.**

Δηλαδή η `cms_cod` υπάρχει ως γραμμή με `status='open'` από την πρώτη
στιγμή, αλλά το UI δείχνει 🔒 και δεν επιτρέπει «Ανάληψη» όσο η
`cms_rates` του ίδιου πελάτη δεν είναι `done`.

### Γιατί όχι «δημιουργούνται μόλις κλείσει η προηγούμενη»

1. **Ορατότητα.** Ο υπεύθυνος πρέπει να βλέπει ολόκληρη την αλυσίδα του
   πελάτη από την αρχή. Με τμηματική δημιουργία, η οθόνη λέει «μένουν 2»
   ενώ στην πραγματικότητα μένουν 5 — και κανείς δεν μπορεί να
   προγραμματίσει.
2. **Το κλείσιμο γίνεται σημείο αποτυχίας.** Αν η δημιουργία της
   επόμενης εξαρτάται από τον κώδικα που κλείνει την προηγούμενη, κάθε
   σφάλμα εκεί σταματά την αλυσίδα σιωπηλά. Με προδημιουργία, η αλυσίδα
   υπάρχει ολόκληρη στη βάση από τη στιγμή της αποδοχής.
3. **Το UNIQUE δουλεύει μόνο έτσι.** Το `uq_client_task_offer` προστατεύει
   από διπλές εγγραφές μόνο αν όλες γράφονται στην ίδια στιγμή, από ένα
   σημείο. Τμηματική δημιουργία σημαίνει πολλαπλά σημεία εισαγωγής,
   καθένα με δική του λογική «υπάρχει ήδη;».
4. **Το ξεκλείδωμα είναι ερώτημα, όχι εργασία.** Δεν χρειάζεται κώδικας
   που «ξυπνά» εργασίες:

```sql
SELECT t.*,
       CASE WHEN tt.depends_on IS NULL THEN 0
            WHEN EXISTS (SELECT 1 FROM 4a_tasks d
                          WHERE d.client_id    = t.client_id
                            AND d.offer_number = t.offer_number
                            AND d.task_code    = tt.depends_on
                            AND d.status IN ('done','na'))
            THEN 0 ELSE 1 END AS locked
  FROM 4a_tasks t
  JOIN 4a_task_types tt ON tt.code = t.task_code
```

Το `d.status IN ('done','na')` είναι σκόπιμο: αν η `cms_rates` σημανθεί
«δεν εφαρμόζεται», η `cms_cod` πρέπει να ξεκλειδώσει — αλλιώς η αλυσίδα
κολλάει για πάντα.

### Στην οθόνη

- **Κλειδωμένη:** αχνή, 🔒, tooltip «Περιμένει: Καταχώρηση τιμών στο CMS».
  Ορατή, όχι κρυμμένη — ο χρήστης πρέπει να ξέρει τι έρχεται.
- **Ξεκλείδωτη και αδιάθετη:** κουμπί «Ανάληψη».
- **Δική μου:** «Έναρξη» / «Παύση» / «Ολοκλήρωση».
- Το κλείδωμα επιβάλλεται **και στον server**. Το UI που κρύβει ένα
  κουμπί δεν είναι έλεγχος.

Οι τρεις αλυσίδες στο seed: `cms_cod` ← `cms_rates`, `cms_fuel` ←
`cms_rates`, `notify_client` ← `open_code`. Οι `cod_form` και `training`
είναι ανεξάρτητες.

---

## 4. Τι ΔΕΝ καλύπτει η Φάση 2

| Εκτός πεδίου | Γιατί / τι λείπει |
|---|---|
| **SLA και `due_at`** | Η στήλη `sla_hours` υπάρχει αλλά είναι `NULL` παντού. Καμία συμπλήρωση `due_at`, κανένα «ληξιπρόθεσμο». Το `ix_status_due` υπάρχει για όταν έρθει. |
| **Ειδοποιήσεις** | Κανένα email, καμία ένδειξη στο μενού, κανένα badge «έχεις Ν εργασίες». Ο χρήστης βλέπει την ουρά μόνο αν ανοίξει τη σελίδα. |
| **Steal / ανακατανομή** | Δεν υπάρχει τρόπος να πάρει κάποιος εργασία που ήδη ανέλαβε άλλος, ούτε να την επιστρέψει στην ουρά. Το `4a_task_events` έχει ήδη `event` ελεύθερο `varchar` ώστε να χωρέσει `unassigned`/`reassigned` όταν έρθει. |
| **Αυτόματη επιλογή αναδόχου** | Απόφαση 23/09: καμία. Όλα `assigned_to = NULL`. |
| **UI διαχείρισης δεξιοτήτων** | Το `4a_user_task_skills` είναι άδειο και γεμίζει μόνο με SQL. Χωρίς γραμμές, η ουρά δεν φιλτράρεται ανά χρήστη. |
| **Επανάληψη σε ανανέωση** | Το κλειδί το επιτρέπει (νέο `offer_number` → νέο σετ), αλλά κανένας κώδικας δεν το πυροδοτεί: η μετάβαση `accepted → accepted` δεν είναι μετάβαση. Χρειάζεται ξεχωριστή ενέργεια. |
| **Διαγραφή / ακύρωση** | Το `status='na'` υπάρχει με `close_reason`, αλλά δεν υπάρχει UI. |
| **Module RBAC** | Χρειάζεται νέα γραμμή `modules` με id `tasks` (πρότυπο: `db/migrations/2026-09-09b_announcements_rbac.sql:26,45`). Οι administrators παίρνουν αυτόματα πλήρη πρόσβαση σε κάθε ενεργό module — `api/auth.php:96` — οπότε δεν χρειάζονται γραμμή στο `role_permissions`. |

---

## Ανοιχτές αποφάσεις πριν γραφτεί κώδικας

1. Το `4a_clients.status` γίνεται `enum`; Και τι απογίνονται τα αχρησιμοποίητα
   `rejected` / `active`;
2. Ποιος γεμίζει το `4a_user_task_skills` και πότε; Χωρίς γραμμές, κανείς
   δεν βλέπει τίποτα στην ουρά.
3. Τι μπαίνει στο `payload` πέρα από `account` και τις υπηρεσίες fuel;
