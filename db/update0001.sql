-- Defines the queries that can be used in wiki
CREATE TABLE queries (
    id INTEGER PRIMARY KEY,
    db TEXT NOT NULL,
    name TEXT NOT NULL,
    sql TEXT NOT NULL
);