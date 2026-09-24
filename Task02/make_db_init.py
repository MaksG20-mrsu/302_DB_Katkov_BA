import csv
import os
import re
import sys

DATASET_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "dataset")
OUTPUT_SQL = "db_init.sql"


def sql_escape(value):
    if value is None:
        return "NULL"
    return "'" + str(value).replace("'", "''") + "'"


def parse_title_year(raw_title):
    match = re.match(r"^(.*)\s+\((\d{4})\)\s*$", raw_title)
    if match:
        return match.group(1).strip(), int(match.group(2))
    return raw_title.strip(), None


def write_header(f):
    f.write("PRAGMA foreign_keys = OFF;\n")
    f.write("BEGIN TRANSACTION;\n\n")


def write_drop_tables(f):
    for table in ("tags", "ratings", "movies", "users"):
        f.write(f"DROP TABLE IF EXISTS {table};\n")
    f.write("\n")


def write_create_tables(f):
    f.write("""CREATE TABLE movies (
    id      INTEGER PRIMARY KEY,
    title   TEXT NOT NULL,
    year    INTEGER,
    genres  TEXT
);

CREATE TABLE ratings (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id   INTEGER NOT NULL,
    movie_id  INTEGER NOT NULL,
    rating    REAL NOT NULL,
    timestamp INTEGER NOT NULL
);

CREATE TABLE tags (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id   INTEGER NOT NULL,
    movie_id  INTEGER NOT NULL,
    tag       TEXT NOT NULL,
    timestamp INTEGER NOT NULL
);

CREATE TABLE users (
    id            INTEGER PRIMARY KEY,
    name          TEXT NOT NULL,
    email         TEXT,
    gender        TEXT,
    register_date TEXT,
    occupation    TEXT
);

""")


def load_movies(f):
    path = os.path.join(DATASET_DIR, "movies.csv")
    with open(path, newline="", encoding="utf-8") as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            movie_id = int(row["movieId"])
            title, year = parse_title_year(row["title"])
            genres = row["genres"]
            year_sql = "NULL" if year is None else str(year)
            f.write(
                f"INSERT INTO movies (id, title, year, genres) VALUES "
                f"({movie_id}, {sql_escape(title)}, {year_sql}, {sql_escape(genres)});\n"
            )
    f.write("\n")


def load_ratings(f):
    path = os.path.join(DATASET_DIR, "ratings.csv")
    with open(path, newline="", encoding="utf-8") as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            user_id = int(row["userId"])
            movie_id = int(row["movieId"])
            rating = float(row["rating"])
            timestamp = int(row["timestamp"])
            f.write(
                f"INSERT INTO ratings (user_id, movie_id, rating, timestamp) VALUES "
                f"({user_id}, {movie_id}, {rating}, {timestamp});\n"
            )
    f.write("\n")


def load_tags(f):
    path = os.path.join(DATASET_DIR, "tags.csv")
    with open(path, newline="", encoding="utf-8") as csvfile:
        reader = csv.DictReader(csvfile)
        for row in reader:
            user_id = int(row["userId"])
            movie_id = int(row["movieId"])
            tag = row["tag"]
            timestamp = int(row["timestamp"])
            f.write(
                f"INSERT INTO tags (user_id, movie_id, tag, timestamp) VALUES "
                f"({user_id}, {movie_id}, {sql_escape(tag)}, {timestamp});\n"
            )
    f.write("\n")


def load_users(f):
    path = os.path.join(DATASET_DIR, "users.txt")
    with open(path, encoding="utf-8") as txt:
        for line in txt:
            line = line.rstrip("\n")
            if not line:
                continue
            parts = line.split("|")
            if len(parts) < 6:
                continue
            user_id, name, email, gender, register_date, occupation = parts[:6]
            f.write(
                f"INSERT INTO users (id, name, email, gender, register_date, occupation) VALUES "
                f"({int(user_id)}, {sql_escape(name)}, {sql_escape(email)}, "
                f"{sql_escape(gender)}, {sql_escape(register_date)}, {sql_escape(occupation)});\n"
            )
    f.write("\n")


def main():
    if not os.path.isdir(DATASET_DIR):
        print(f"Dataset directory not found: {DATASET_DIR}", file=sys.stderr)
        sys.exit(1)

    with open(OUTPUT_SQL, "w", encoding="utf-8") as f:
        write_header(f)
        write_drop_tables(f)
        write_create_tables(f)
        load_movies(f)
        load_ratings(f)
        load_tags(f)
        load_users(f)
        f.write("COMMIT;\n")

    print(f"Generated {OUTPUT_SQL}")


if __name__ == "__main__":
    main()
