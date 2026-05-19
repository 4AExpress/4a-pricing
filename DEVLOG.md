# 4A Express — Dev Log

## 2026-05-19 — DB Credentials Fix

**Πρόβλημα:** api/shelf.php (νέα έκδοση από Claude Code) είχε hardcoded DB credentials που δεν δούλευαν.

**Λύση:** Επαναφορά στο παλιό shelf.php (v1.0) που χρησιμοποιεί require_once 'config.php'.

**Κανόνας:** Κανένα PHP αρχείο δεν γράφει hardcoded credentials. Πάντα require_once 'config.php'.

**config.php:** Υπάρχει μόνο στον server — ΔΕΝ είναι στο repo (είναι στο .gitignore).
