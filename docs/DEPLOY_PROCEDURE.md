# Διαδικασία deploy backend (Β1–Β9)

Ισχύει για κάθε deploy του `api/` (PHP) και κάθε migration του `db/migrations/`.
Συμπληρώνει τον κανόνα deploy του `CLAUDE.md` και δεν τον αντικαθιστά.

Τα `<host>`, `<user>`, `<port>` και `<webroot>` είναι **placeholders**. Τα
πραγματικά στοιχεία πρόσβασης **δεν** γράφονται σε κανένα αρχείο του repo.
Το `<scratchpad>` είναι τοπικός φάκελος εκτός repo.

## Κανόνες

- **Κάθε βήμα δείχνει πρώτα την εντολή και περιμένει έγκριση.** Ισχύει και για τους ελέγχους μόνο ανάγνωσης.
- **Σε οποιαδήποτε απόκλιση: ΣΤΟΠ.** Καμία διόρθωση επί τόπου, καμία συνέχεια «επειδή μοιάζει ακίνδυνο».
- **Ποτέ** `git pull`, `git reset` ή `git clean` στον server.
- **Ποτέ** DDL σε endpoint. Το DDL τρέχει μόνο με migration (Β4).
- **Ποτέ** `--force`, σε καμία εντολή.

## Βήματα

### Β1 Probe πριν (μόνο ανάγνωση)

- Το script στέλνεται **μέσω stdin**. **Κανένα αρχείο δεν γράφεται στον server.**
- Διαβάζει:
  - το charset της σύνδεσης και των πινάκων
  - αν υπάρχουν τα `pdo_sqlite` και `bcmath`
  - το md5 **ανά εργασία πελάτη** και το md5 του συνόλου
- Η έξοδος αποθηκεύεται τοπικά στο `<scratchpad>/B1_before`.

```
ssh -p <port> <user>@<host> "php" < <scratchpad>/probe.php > <scratchpad>/B1_before
```

### Β2 Τα ζωντανά αρχεία είναι αυτά που λέει το git

- Για κάθε αρχείο που θα αλλάξει: το md5 του ζωντανού, **κανονικοποιημένο χωρίς CR**, πρέπει να είναι ίσο με το md5 του αρχείου στο **προηγούμενο commit**.
- Το πλήθος των CR στο ζωντανό αρχείο πρέπει να είναι **0**.
- **Αν το md5 διαφέρει: ΣΤΟΠ.** Σημαίνει ότι έγινε αλλαγή εκτός git.

```
ssh -p <port> <user>@<host> "tr -d '\r' < <webroot>/api/<αρχείο> | md5sum; tr -cd '\r' < <webroot>/api/<αρχείο> | wc -c"
git show HEAD~1:api/<αρχείο> | md5sum
```

### Β3 Αντίγραφα εκτός webroot

- Κάθε αρχείο που θα αλλάξει αντιγράφεται στο `~/backups/<αρχείο>.PRE-<timestamp>`.
- Κρατιέται το md5 κάθε αντιγράφου.
- **Ποτέ** αντίγραφο `.bak` μέσα στο `<webroot>`.

```
ssh -p <port> <user>@<host> "cp -p <webroot>/api/<αρχείο> ~/backups/<αρχείο>.PRE-<timestamp> && md5sum ~/backups/<αρχείο>.PRE-<timestamp>"
```

### Β4 Migration

- Ο runner χτίζεται από το blob του git (`git show HEAD:db/migrations/<αρχείο>.sql`) και στέλνεται **μέσω stdin**.
- Είναι **επαναλήψιμος**: κάθε έλεγχος γίνεται στο `information_schema` με `TABLE_SCHEMA = DATABASE()`.
- **Σταματά στο πρώτο σφάλμα.**
- Η **αναμενόμενη έξοδος δηλώνεται ΠΡΙΝ** από την εκτέλεση. Αν η πραγματική διαφέρει: ΣΤΟΠ.

### Β5 SCP από το blob του HEAD

- Κάθε αρχείο βγαίνει από το git, **ποτέ από τον δίσκο**.
- **Μία εντολή ανά αρχείο.**

```
git show HEAD:api/<αρχείο> > <scratchpad>/<αρχείο>
scp -P <port> <scratchpad>/<αρχείο> <user>@<host>:<webroot>/api/<αρχείο>
```

### Β6 Έλεγχοι αρχείου

- **md5 του server = md5 του blob**: `git show HEAD:api/<αρχείο> | md5sum`.
- Το `php -l` στο αρχείο του server πρέπει να δίνει «No syntax errors».
- Το `curl` χωρίς token πρέπει να δίνει **401**.
- **Ο έλεγχος με token γίνεται ΜΟΝΟ από τον Απόστολο, στον browser.**

```
ssh -p <port> <user>@<host> "md5sum <webroot>/api/<αρχείο>; php -l <webroot>/api/<αρχείο>"
curl -s -o /dev/null -w "%{http_code}\n" https://4aexpress.com/api/<endpoint>
```

### Β7 Probe μετά

- Το ίδιο script με το Β1. Η έξοδος αποθηκεύεται στο `<scratchpad>/B7_after`.
- `diff` με το `B1_before`: οι εργασίες πελάτη πρέπει να είναι **ΙΔΙΕΣ byte προς byte**.

### Β8 Τεστ PHP στον server

- Στέλνονται **μέσω stdin**, ένα αρχείο τεστ τη φορά.
- Τρέχουν σε **SQLite στη μνήμη, όχι σε MySQL**.
- Αποτέλεσμα: **0 ΛΑΘΟΣ**, κανένα `Fatal` και κανένα `Warning`.

### Β9 Τοπικά: push και frontend

1. `git fetch`
2. Μόνο αν χρειαστεί: `git pull --rebase`. **ΠΟΤΕ** `--force`.
3. Τεστ frontend: `node tests/frontend/run_all.js`
4. `git push`
5. Στο GitHub Actions, το «pages build and deployment» πρέπει να είναι ✓.
6. Στον browser: Ctrl+Shift+R.
7. Στιγμιότυπο της σελίδας.

## Επαναφορά

1. **Πρώτα τα αρχεία**, από το `~/backups/<αρχείο>.PRE-<timestamp>`. Ακολουθεί έλεγχος md5 με το αντίγραφο του Β3.
2. **Μετά το αντίστροφο migration**, με τους ίδιους κανόνες με το Β4.
