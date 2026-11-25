-- Лабораторная работа 5. Катков Богдан.
-- Запустить после db_init.sql. id назначаются SQLite автоматически.
-- Используются учебные адреса example.com.
PRAGMA foreign_keys = ON;
BEGIN TRANSACTION;

-- Обеспечиваем наличие нужных справочных значений без привязки к их id.
INSERT INTO occupations (name)
SELECT 'student' WHERE NOT EXISTS (
    SELECT 1 FROM occupations WHERE name = 'student'
);
INSERT INTO genres (name)
SELECT 'Animation' WHERE NOT EXISTS (SELECT 1 FROM genres WHERE name = 'Animation');
INSERT INTO genres (name)
SELECT 'Drama' WHERE NOT EXISTS (SELECT 1 FROM genres WHERE name = 'Drama');
INSERT INTO genres (name)
SELECT 'Sci-Fi' WHERE NOT EXISTS (SELECT 1 FROM genres WHERE name = 'Sci-Fi');

-- register_date не указывается: DEFAULT берёт текущую системную дату (UTC).
INSERT INTO users (first_name, last_name, email, gender, occupation_id) VALUES
    ('Богдан', 'Катков', 'katkov.ba@example.com', 'male',
     (SELECT id FROM occupations WHERE name = 'student')),
    ('Никита', 'Михеев', 'mikheev.n@example.com', 'male',
     (SELECT id FROM occupations WHERE name = 'student')),
    ('Максим', 'Гудков', 'gudkov.m@example.com', 'male',
     (SELECT id FROM occupations WHERE name = 'student')),
    ('Артем', 'Сарайкин', 'saraykin.a@example.com', 'male',
     (SELECT id FROM occupations WHERE name = 'student')),
    ('Влад', 'Мамулев', 'mamulev.v@example.com', 'male',
     (SELECT id FROM occupations WHERE name = 'student'));

-- Временная таблица запоминает id новых фильмов. Она нужна только во время
-- выполнения скрипта и удаляется в конце. Поиск по названию фильма не нужен:
-- даже существующий фильм с таким же названием не приведёт к путанице.
CREATE TEMP TABLE new_movies (
    movie_id   INTEGER NOT NULL,
    genre_name TEXT NOT NULL,
    rating     REAL NOT NULL
);

-- last_insert_rowid() возвращает id только что добавленного фильма.
INSERT INTO movies (title, year) VALUES ('Soul', 2020);
INSERT INTO new_movies VALUES (last_insert_rowid(), 'Animation', 5.0);

INSERT INTO movies (title, year) VALUES ('Oppenheimer', 2023);
INSERT INTO new_movies VALUES (last_insert_rowid(), 'Drama', 4.5);

INSERT INTO movies (title, year) VALUES ('Dune', 2021);
INSERT INTO new_movies VALUES (last_insert_rowid(), 'Sci-Fi', 4.5);

INSERT INTO movie_genres (movie_id, genre_id)
SELECT n.movie_id, g.id
FROM new_movies AS n JOIN genres AS g ON g.name = n.genre_name;

-- Три отзыва от Катковa Богдана. Дату отзыва задаёт DEFAULT timestamp.
INSERT INTO ratings (user_id, movie_id, rating)
SELECT u.id, n.movie_id, n.rating
FROM users AS u CROSS JOIN new_movies AS n
WHERE u.email = 'katkov.ba@example.com';

DROP TABLE new_movies;

COMMIT;
