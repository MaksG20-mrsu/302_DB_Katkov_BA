# Task02 — ETL: генерация SQL-скрипта и загрузка данных в SQLite

## Требования к окружению

Для корректной работы скрипта `db_init.bat` на компьютере должны быть установлены:

- **Python 3** — https://www.python.org/downloads/ (при установке на Windows отметить галочку *Add Python to PATH*). Проверка: `python3 --version`.
- **SQLite** — утилита командной строки `sqlite3`. Скачать: https://www.sqlite.org/download.html (раздел *Precompiled Binaries for Windows*, файл `sqlite-tools-win-x64-*.zip`). Папку с `sqlite3.exe` добавить в `PATH`. Проверка: `sqlite3 --version`.
- **Bash** — на Windows идёт вместе с Git for Windows (Git Bash).

## Состав каталога

| Файл | Назначение |
|------|-----------|
| `db_init.bat` | Кроссплатформенный shell-скрипт: запускает генератор и загружает SQL в SQLite |
| `make_db_init.py` | Утилита на Python: читает `../dataset/*` и генерирует `db_init.sql` |
| `db_init.sql` | SQL-скрипт (создаётся автоматически): `DROP TABLE`, `CREATE TABLE`, `INSERT INTO` |
| `movies_rating.db` | База данных SQLite (создаётся автоматически после запуска `db_init.bat`) |

## Запуск

Из каталога `Task02`:

```bash
bash db_init.bat
