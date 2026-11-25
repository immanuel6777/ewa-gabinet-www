-- Bony: konta panelu w bazie (wpis czatu 2026-11-25 13:20). Kod działa też PRZED tą migracją, dopóki `konta_z_bazy` = false.
-- Kolejność wdrożenia: plan/BONY-KONTA-WDROZENIE.md (migracja → przeniesienie kont → przełącznik → test → ewentualne wycofanie).
--
-- phpMyAdmin OVH → baza bonów → SQL → wkleić CAŁY plik → Wykonaj (RAZ).
-- Drugie wykonanie: ALTER zgłosi „Duplicate column name” (kolumna już jest), CREATE TABLE IF NOT EXISTS niczego nie psuje.

-- konta panelu: hash bcrypt (password_hash), wersja hasła unieważnia sesje po zmianie/resecie/„wyloguj wszędzie”
CREATE TABLE IF NOT EXISTS voucher_accounts (
  login               VARCHAR(40) NOT NULL PRIMARY KEY,
  imie                VARCHAR(60) NOT NULL,
  rola                ENUM('realizacja','admin') NOT NULL DEFAULT 'realizacja',
  rezygnacje          TINYINT(1) NOT NULL DEFAULT 0,            -- uprawnienie jak 'rezygnacje' => true w konfigu (Ewa)
  haslo_hash          VARCHAR(255) NOT NULL,
  zmiana_wymuszona    TINYINT(1) NOT NULL DEFAULT 0,            -- 1 = hasło tymczasowe, przy logowaniu najpierw własne
  aktywne             TINYINT(1) NOT NULL DEFAULT 1,
  wersja_hasla        INT UNSIGNED NOT NULL DEFAULT 1,
  utworzono_at        DATETIME NOT NULL,
  zmieniono_at        DATETIME NOT NULL,
  haslo_zmienione_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- historia zmian kont (kto, kiedy, co) – bez haseł i hashy
CREATE TABLE IF NOT EXISTS voucher_account_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login      VARCHAR(40) NOT NULL,
  akcja      VARCHAR(30) NOT NULL,
  szczegoly  VARCHAR(255) NULL,
  kto        VARCHAR(40) NOT NULL,
  kiedy      DATETIME NOT NULL,
  KEY voucher_account_log_login (login, kiedy)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sesja pamięta wersję hasła konta (NULL = sesja sprzed przełączenia – po włączeniu konta_z_bazy trzeba się zalogować ponownie)
ALTER TABLE voucher_sessions ADD COLUMN wersja_hasla INT UNSIGNED NULL AFTER expires_at;

-- sprawdzenie
SELECT 'voucher_accounts' AS tabela, COUNT(*) AS wierszy FROM voucher_accounts
UNION ALL SELECT 'voucher_account_log', COUNT(*) FROM voucher_account_log;
