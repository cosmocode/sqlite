-- Defines the queries that can be used in wiki
CREATE TABLE queries
(
    db   TEXT NOT NULL,
    name TEXT NOT NULL,
    sql  TEXT NOT NULL,
    PRIMARY KEY (db, name)
);
