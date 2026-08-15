-- Schemat testowy i uprawnienia dla konta aplikacji.
--
-- Obraz MySQL-a nadaje uprawnienia wyłącznie do bazy wskazanej w MYSQL_DATABASE,
-- więc bez tego skryptu konto aplikacji nie zobaczyłoby drugiego schematu.
--
-- ⚠️ Skrypty z docker-entrypoint-initdb.d wykonują się TYLKO przy tworzeniu
-- pustego katalogu danych. Ręczny odpowiednik dla istniejącego wolumenu jest
-- w docs/operations/docker.md.

CREATE DATABASE IF NOT EXISTS `lowiska_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `lowiska_test`.* TO 'lowiska'@'%';

-- Uprawnienia do schematów zrównoleglonych nadawane z góry: samo zrównoleglenie
-- jest poza zakresem zadania 004, ale dołożenie tego GRANT-a później wymagałoby
-- ręcznego wejścia do bazy w każdym istniejącym środowisku.
GRANT ALL PRIVILEGES ON `lowiska\_test\_%`.* TO 'lowiska'@'%';

FLUSH PRIVILEGES;
