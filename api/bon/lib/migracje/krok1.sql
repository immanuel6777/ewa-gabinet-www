-- Bony, krok 1 (2026-11-23): rezygnacje, zwroty, przedłużenie, przypomnienia (brief plan/PROMPT-CLAUDE-CODE-BONY-ZWROTY.md).
-- Plik leży w lib/ (zablokowanym w .htaccess), bo czyta go też test-ovh.php: przed testami wykonuje tę migrację na kopiach
-- tabel test_* – czyli sprawdza ją na prawdziwym MySQL OVH, zanim dotknie prawdziwych tabel.
--
-- Kolejność na OVH:
--  1. phpMyAdmin → tabela vouchers → Eksport (SQL, szybki) – kopia przed zmianą; zapisać u siebie (dane osobowe, nie do repo).
--  2. Wrzuta kodu kroku 1, potem test-ovh.php (z test_token): linia „Migracja kroku 1 na kopiach test_*: OK”.
--  3. phpMyAdmin → SQL → wkleić ten plik → Wykonaj (RAZ, po bony-migracja-2026-11-23.sql).
--  4. test-ovh.php jeszcze raz (już bez migracji na kopiach), log.php: tabele voucher_withdrawals i voucher_withdrawal_tokens są.
-- Kod kroku 1 działa też przed punktem 3 (nowych kolumn i tabel jeszcze nie używa); log.php pokaże wtedy „brak tabeli”.
-- Drugie wykonanie: ALTER zgłosi „Duplicate column name” (już jest), CREATE TABLE IF NOT EXISTS niczego nie psuje.

-- nowy status 'withdrawn' = rezygnacja złożona (bon zablokowany, czeka na zwrot); po zwrocie → 'refunded'
ALTER TABLE vouchers
  MODIFY COLUMN status ENUM('valid','used','expired','refunded','held','withdrawn') NOT NULL DEFAULT 'valid',
  ADD COLUMN expires_at_pierwotna DATE NULL AFTER mail_wyslany_at,
  ADD COLUMN przedluzony_at DATETIME NULL AFTER expires_at_pierwotna,
  ADD COLUMN przypomnienie_at DATETIME NULL AFTER przedluzony_at;

CREATE TABLE IF NOT EXISTS voucher_withdrawals (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  voucher_id            INT UNSIGNED NOT NULL,
  zlozono_at            DATETIME NOT NULL,
  potwierdzono_at       DATETIME NOT NULL,
  kanal                 ENUM('strona','mail','gabinet') NOT NULL,
  kto                   VARCHAR(40) NOT NULL,
  saldo_pln             DECIMAL(10,2) NOT NULL,
  kwota_pln             DECIMAL(10,2) NOT NULL,
  zwrot_stan            ENUM('oczekuje','zlecony','potwierdzony','nieudany','anulowana') NOT NULL DEFAULT 'oczekuje',
  zwrot_zlecono_at      DATETIME NULL,
  zwrot_kwota_pln       DECIMAL(10,2) NULL,
  zwrot_potwierdzono_at DATETIME NULL,
  zwrot_ref             VARCHAR(100) NULL,
  zwrot_blad            VARCHAR(255) NULL,
  UNIQUE KEY voucher_withdrawals_bon (voucher_id),
  KEY voucher_withdrawals_stan (zwrot_stan),
  CONSTRAINT voucher_withdrawals_fk FOREIGN KEY (voucher_id) REFERENCES vouchers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voucher_withdrawal_tokens (
  token_hash   CHAR(64) NOT NULL PRIMARY KEY,
  voucher_id   INT UNSIGNED NOT NULL,
  formularz_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  uzyty_at     DATETIME NULL,
  KEY voucher_withdrawal_tokens_bon (voucher_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
