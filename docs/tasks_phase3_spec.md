# Σύστημα εργασιών — Φάση 3: η οθόνη

**Κατάσταση:** προδιαγραφή. Καμία γραμμή κώδικα δεν έχει γραφτεί.
**Ημερομηνία:** 23/09/2026
**Προϋποθέσεις:** Φάσεις 1 και 2 είναι ζωντανές — `6332447`, `ebded61`,
`5237f24`, `d9fe095`. Οι εργασίες ήδη παράγονται σε κάθε μετάβαση σε
`accepted`· απλώς δεν τις βλέπει κανείς.

> Δημόσια εκδοχή. Ονόματα πελατών και προσωπικά στοιχεία δεν μπαίνουν εδώ.

---

## 1. RBAC — νέο module `tasks`

### Το πρότυπο

`db/migrations/2026-09-09b_announcements_rbac.sql:26-30` — δήλωση module:

```sql
INSERT INTO `modules` (`id`,`label`,`icon`,`sort_order`,`active`) VALUES
  ('announcements-admin',  'Σύνταξη ανακοινώσεων', 'ti-speakerphone', 55, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);
```

`db/migrations/2026-09-09b_announcements_rbac.sql:44-58` — ρητές γραμμές
ανά ρόλο, **ακόμα και για το «όχι»**:

```sql
INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES (2,'announcements-admin',0,0,0,0,0), ...
ON DUPLICATE KEY UPDATE `can_view`=VALUES(`can_view`), ...
```

Το σχόλιο εκεί εξηγεί γιατί: *«ρητές γραμμές, ώστε το “όχι” να είναι
καταγεγραμμένη απόφαση και όχι απουσία εγγραφής»*.

### Οι πραγματικοί ρόλοι (έλεγχος παραγωγής 23/09)

| id | name | label |
|---|---|---|
| 1 | `administrator` | Διαχειριστής |
| 2 | `manager` | Προϊστάμενος |
| 3 | `staff` | Υπάλληλος |
| 4 | `readonly` | Ανάγνωση μόνο |
| 5 | `trainee` | Εκπαιδευόμενος |

Δεν υπάρχει `admin` ούτε `superadmin`.

### Ποια actions χρειάζονται

Ο πίνακας έχει πέντε στήλες: `can_view`, `can_add`, `can_edit`,
`can_delete`, `can_export`. Για τις εργασίες χρειάζονται **δύο**:

| action | τι σημαίνει εδώ |
|---|---|
| `view` | βλέπει την οθόνη και τις δικές του / την ουρά |
| `edit` | αναλαμβάνει, αφήνει, ολοκληρώνει, σημαίνει `na` |

**`add` δεν χρησιμοποιείται:** τις εργασίες τις δημιουργεί ο κώδικας της
Φάσης 2, ποτέ ο χρήστης. **`delete` δεν δίνεται σε κανέναν** — εργασία
δεν σβήνεται, σημαίνεται `na` με λόγο. Ίδια λογική με τις ανακοινώσεις.
`export` όχι στη Φάση 3.

### Πρόταση δικαιωμάτων

```sql
INSERT INTO `modules` (`id`,`label`,`icon`,`sort_order`,`active`) VALUES
  ('tasks', 'Εργασίες', 'ti-checklist', 22, 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

INSERT INTO `role_permissions`
  (`role_id`,`module_id`,`can_view`,`can_add`,`can_edit`,`can_delete`,`can_export`)
VALUES
  (2,'tasks',1,0,1,0,0),   -- manager:  βλέπει και αναλαμβάνει
  (3,'tasks',1,0,1,0,0),   -- staff:    το ίδιο (η Αντωνία έχει ήδη 2 δεξιότητες)
  (4,'tasks',1,0,0,0,0),   -- readonly: βλέπει, δεν αγγίζει
  (5,'tasks',0,0,0,0,0)    -- trainee:  τίποτα
ON DUPLICATE KEY UPDATE ...;
```

`sort_order = 22` τοποθετεί το module ανάμεσα σε `pricelist-clients` (20)
και `offers` (25) — εκεί ανήκει ροϊκά.

**Οι administrators δεν χρειάζονται γραμμή.** Το `api/auth.php:96-102`
τους δίνει `['view'=>1,'add'=>1,'edit'=>1,'delete'=>1,'export'=>1]` σε
**κάθε ενεργό module**, διαβάζοντας απευθείας τον `modules`. Αρκεί το
`active = 1`.

> **Προσοχή στο `user_permissions`.** Για κάθε μη-administrator, μια
> γραμμή εκεί **αντικαθιστά ολόκληρο** το module (`auth.php:104-119`) —
> δεν συγχωνεύεται με τον ρόλο. Αυτό ακριβώς είχε κλειδώσει έξω τον
> χρήστη 7 από το `offers`, όπως καταγράφει το migration των ανακοινώσεων.

---

## 2. Πρότυπο σελίδας για το `tasks.html`

**Σκελετός: `frontend/charge-limits.html`** (322 γραμμές, το νεότερο και
καθαρότερο).

| Τι παίρνουμε | Γραμμές |
|---|---|
| nav bar με `.nav-btn` / `.nav-btn.active` | `:24-26` (CSS), `:89-96` (σύνδεσμοι) |
| `apiFetch()` με Bearer + χειρισμό 401 | `:200-208` |
| `doLogout()` | `:209` |
| boot guard — χωρίς συνεδρία, ανακατεύθυνση | `:311-320` |

```js
(function(){
  const u = sessionStorage.getItem('4a_current_user');
  if(!u){ window.location.href='pricelist-editor.html'; return; }
  ...
})();
```

**Για πίνακα με ενέργειες ανά γραμμή**, πρότυπο είναι το
`frontend/services.html` (585 γρ.) — έχει λίστα με κουμπιά ανά σειρά.

Και τα δύο χρησιμοποιούν **`sessionStorage`** (`4a_current_user`), όχι
`localStorage` — να μην αντιγραφεί λάθος από το `pricelist-editor.html`.

### Δύο προβλήματα του μενού που πρέπει να ξέρουμε

**Το nav είναι αντιγραμμένο σε 11 σελίδες και ήδη ασύμφωνο.** Έλεγχος 23/09:

| Σύνδεσμος | Σε πόσες από τις 11 σελίδες |
|---|---|
| `pricelist-editor`, `pricelist-clients`, `services`, `users`, `zones`, `help` | σχεδόν παντού |
| `charge-limits.html` | **1** (μόνο στη δική της) |
| `roles.html` | **1** (μόνο στο `users.html`) |
| `legal.html` | 9 |

Δηλαδή **η σελίδα Όρια Χρεώσεων είναι ουσιαστικά κρυφή** — φτάνεις μόνο
με απευθείας URL. Αν το `tasks.html` μπει μόνο στον δικό του nav, θα έχει
την ίδια τύχη.

**Απόφαση που χρειάζεται:** ο σύνδεσμος μπαίνει με το χέρι και στις 11,
ή δεχόμαστε ότι η οθόνη είναι προσβάσιμη μόνο από
`pricelist-clients.html` (η φυσική αφετηρία, αφού από εκεί γίνεται
`accepted`); Η δεύτερη επιλογή είναι μικρότερη αλλά επαναλαμβάνει το
λάθος του `charge-limits`.

Δεν προτείνω κοινό `nav.js` σε αυτή τη φάση: θα άγγιζε και τις 11
σελίδες, δηλαδή πολύ μεγαλύτερο ρίσκο από την ίδια τη Φάση 3.

---

## 3. `api/tasks.php` — ενέργειες

Πρότυπο: `api/shelf.php:18-19` (δικαίωμα ανά μέθοδο), `:36` (GET με
`action`), `:72-73` (POST με `action`), και `api/charge_limits.php:98`
(`405` στο τέλος).

```php
if ($method === 'GET')  { $session = require_permission('tasks', 'view'); }
if ($method === 'POST') { $session = require_permission('tasks', 'edit'); }
```

### Υπογραφές

| Ενέργεια | Κλήση | Σώμα / παράμετροι |
|---|---|---|
| **list** | `GET tasks.php?view=mine\|queue\|all` | — |
| **claim** | `POST {action:'claim', id}` | — |
| **release** | `POST {action:'release', id}` | προαιρετικό `note` |
| **done** | `POST {action:'done', id}` | προαιρετικό `note` |
| **na** | `POST {action:'na', id, close_reason}` | `close_reason` **υποχρεωτικό** |

Επιστροφή παντού: `{ok:true, task:{...}}` με την ενημερωμένη εργασία,
ώστε η οθόνη να μην ξαναφορτώνει όλη τη λίστα.

### Έλεγχοι ανά ενέργεια

Το `require_permission` είναι **το κατώφλι, όχι ο έλεγχος**. Κάθε ενέργεια
χρειάζεται δικό της:

**`claim`**
1. η εργασία υπάρχει και είναι `status='open'`
2. `assigned_to IS NULL` — αλλιώς `409`, κάποιος πρόλαβε
3. **δεν είναι κλειδωμένη** από `depends_on` (§4) — ο server ξαναϋπολογίζει, δεν εμπιστεύεται το UI
4. **ο χρήστης έχει τη δεξιότητα**: γραμμή στο `4a_user_task_skills` με
   `task_code` και `country IN (χώρα_πελάτη, 'BOTH')` — **ή** είναι administrator
5. `UPDATE ... SET assigned_to=?, status='in_progress' WHERE id=? AND assigned_to IS NULL`
   → το `AND assigned_to IS NULL` κάνει τον αγώνα ταχύτητας αδύνατο· αν
   `rowCount()===0`, κάποιος πρόλαβε
6. event `assigned`

**`release`** — μόνο ο **ίδιος ο ανάδοχος** ή administrator. `assigned_to=NULL`,
`status='open'`, event `unassigned`.

**`done`** — μόνο ο ανάδοχος ή administrator. `status='done'`,
`closed_at=NOW()`, `closed_by=?`, event `done`.

**`na`** — μόνο ο ανάδοχος ή administrator. **Απαιτεί `close_reason`**, μη
κενό μετά από `trim` (`400` αλλιώς). `status='na'`, `closed_at`,
`closed_by`, `close_reason`, event `na`.

**Καμία ενέργεια σε εργασία ήδη `done`/`na`** χωρίς ρητό `reopen` — που
δεν υπάρχει στη Φάση 3.

### Ορατότητα δεδομένων

Το `GET` πρέπει να σέβεται το `pricelist_scope`, όπως το
`api/clients.php:26,28`: χρήστης με `scope='GR'` δεν βλέπει εργασίες
κυπριακών πελατών. Απαιτεί `JOIN 4a_clients` για τη χώρα — η
`4a_tasks` δεν την κρατά.

---

## 4. Το κλείδωμα `depends_on`

### Υπολογίζεται **server-side** και έρχεται ως πεδίο `locked`

```sql
SELECT t.*,
       c.`name` AS client_name, c.`country`,
       tt.`label`, tt.`depends_on`,
       dt.`label` AS blocked_by_label,
       CASE WHEN tt.`depends_on` IS NULL THEN 0
            WHEN EXISTS (SELECT 1 FROM `4a_tasks` d
                          WHERE d.`client_id`    = t.`client_id`
                            AND d.`offer_number` = t.`offer_number`
                            AND d.`task_code`    = tt.`depends_on`
                            AND d.`status` IN ('done','na'))
            THEN 0 ELSE 1 END AS `locked`
  FROM `4a_tasks` t
  JOIN `4a_task_types` tt ON tt.`code` = t.`task_code`
  JOIN `4a_clients`    c  ON c.`id`    = t.`client_id`
  LEFT JOIN `4a_task_types` dt ON dt.`code` = tt.`depends_on`
```

Το `d.status IN ('done','na')` είναι σκόπιμο: αν η `cms_rates` σημανθεί
«δεν εφαρμόζεται», η `cms_cod` **πρέπει** να ξεκλειδώσει — αλλιώς η
αλυσίδα κολλάει για πάντα.

### Γιατί όχι στον browser

1. **Ο server πρέπει να το ξαναελέγξει στο `claim`** ούτως ή άλλως. Δύο
   υλοποιήσεις του ίδιου κανόνα αποκλίνουν· μία, στο SQL, δεν αποκλίνει.
2. **Ο browser δεν έχει τα δεδομένα.** Η «Ουρά αδιάθετων» δείχνει εργασίες
   πολλών πελατών· για να κρίνει κλείδωμα θα χρειαζόταν **όλες** τις
   εργασίες κάθε πελάτη που εμφανίζεται — δηλαδή δεύτερο, βαρύτερο
   ερώτημα.
3. **Είναι ένα `CASE` στο ίδιο ερώτημα**, χωρίς επιπλέον round-trip.

Το `blocked_by_label` έρχεται μαζί, ώστε το tooltip να λέει «Περιμένει:
Καταχώρηση τιμών στο CMS» χωρίς δεύτερη αναζήτηση στον browser.

### Στην οθόνη

- **Κλειδωμένη:** αχνή, 🔒, tooltip με το `blocked_by_label`. **Ορατή**,
  όχι κρυμμένη — ο χρήστης πρέπει να ξέρει τι έρχεται.
- **Ξεκλείδωτη, αδιάθετη, έχω τη δεξιότητα:** κουμπί «Ανάληψη».
- **Δική μου:** «Ολοκλήρωση» / «Δεν εφαρμόζεται» / «Αφήνω».
- Το κουμπί που λείπει **δεν είναι έλεγχος** — ο server απορρίπτει ούτως ή άλλως.

---

## 5. Οι τρεις προβολές

| Προβολή | Φίλτρο | Δεξιότητες; |
|---|---|---|
| **Οι εργασίες μου** | `assigned_to = :me AND status IN ('in_progress','paused')` | **ΟΧΙ** |
| **Ουρά αδιάθετων** | `assigned_to IS NULL AND status='open'` | **ΝΑΙ** |
| **Όλες** | καμία | **ΟΧΙ** — μόνο administrators |

**«Οι εργασίες μου» δεν φιλτράρεται με δεξιότητες.** Αν κάποιος ανέλαβε
εργασία και μετά του αφαιρεθεί η δεξιότητα, η εργασία δεν πρέπει να
εξαφανιστεί από την οθόνη του — θα έμενε «δική του» και αόρατη. Την
βλέπει και μπορεί να την αφήσει.

**Η ουρά φιλτράρεται:**

```sql
AND (:isAdmin = 1 OR EXISTS (
      SELECT 1 FROM `4a_user_task_skills` s
       WHERE s.`user_id`   = :me
         AND s.`task_code` = t.`task_code`
         AND s.`country` IN (c.`country`, 'BOTH')))
```

Με τα σημερινά δεδομένα (22 γραμμές, όλες GR): οι τρεις GR managers
βλέπουν τα 6 ή 7 είδη, η Αντωνία **μόνο** `notify_client` και `cod_form`,
και **οι κυπριακές εργασίες δεν φαίνονται σε κανέναν** — σκόπιμο, είναι
το σήμα ότι λείπει η απόφαση για την Κύπρο.

**«Όλες»** είναι η ουρά διαχειριστή: `administrator` μόνο, χωρίς φίλτρο
δεξιοτήτων, με ορατό `assigned_to`. Εκεί φαίνονται οι κυπριακές που
κανείς άλλος δεν βλέπει.

Προτείνω **μετρητή** δίπλα σε κάθε καρτέλα (π.χ. «Ουρά (12)») — φτηνό,
ίδιο `WHERE`, και είναι το μόνο σήμα που θα έχει ο χρήστης πριν φτιαχτούν
οι ειδοποιήσεις.

---

## 6. Τι ΔΕΝ μπαίνει στη Φάση 3

| Εκτός πεδίου | Γιατί |
|---|---|
| **SLA / `due_at`** | Τα `sla_hours` είναι `NULL` παντού. Καμία ένδειξη «ληξιπρόθεσμο». Το `ix_status_due` περιμένει. |
| **Ειδοποιήσεις** | Κανένα email, κανένα badge στο μενού. Ο χρήστης βλέπει την ουρά μόνο αν ανοίξει τη σελίδα. |
| **Steal / ανάθεση σε άλλον** | Ο administrator δεν μπορεί να πάρει εργασία από κάποιον ή να την αναθέσει. Μόνο `release` από τον ίδιο τον ανάδοχο. Το `event` είναι `varchar` και χωράει `reassigned` όταν έρθει. |
| **`pause` / `resume`** | Το `status='paused'` και το `paused_at` υπάρχουν στο σχήμα αλλά **καμία ενέργεια δεν τα θέτει**. Η προβολή «Οι εργασίες μου» τα δείχνει αν υπάρξουν. |
| **`reopen`** | `done`/`na` είναι τελικά. Λάθος κλείσιμο θέλει SQL. |
| **UI δεξιοτήτων** | Το `4a_user_task_skills` αλλάζει μόνο με migration. |
| **CRUD τύπων εργασιών** | Το `4a_task_types` είναι πίνακας ακριβώς για να αλλάζει χωρίς deploy, αλλά η οθόνη έρχεται αργότερα. |
| **Επανάληψη σε ανανέωση** | Το κλειδί το επιτρέπει, κανένας κώδικας δεν το πυροδοτεί. |
| **Ιστορικό εργασίας στην οθόνη** | Το `4a_task_events` γεμίζει αλλά δεν εμφανίζεται. |
| **Μαζικές ενέργειες** | Μία-μία. |
| **Επεξεργασία `payload`** | Μόνο ανάγνωση. |

---

## Ανοιχτές αποφάσεις πριν γραφτεί κώδικας

1. **Ο σύνδεσμος στο μενού μπαίνει και στις 11 σελίδες ή μόνο σε μία;**
   (βλ. §2 — το `charge-limits.html` είναι το προειδοποιητικό παράδειγμα)
2. **`readonly` βλέπει τις εργασίες;** Η πρόταση λέει ναι, μόνο ανάγνωση.
3. **Οι κυπριακές εργασίες** θα συσσωρεύονται ορατές μόνο στους
   administrators. Θέλουμε ορατή προειδοποίηση στην οθόνη όταν ξεπεράσουν
   έναν αριθμό, ή αρκεί η στοίβα;
