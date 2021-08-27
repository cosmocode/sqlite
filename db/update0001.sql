-- Defines the queries that can be used in wiki
CREATE TABLE queries (
    id INTEGER PRIMARY KEY,
    db TEXT NOT NULL,
    name TEXT NOT NULL,
    sql TEXT NOT NULL
);

-- Defines the syntax parsers used query columns
CREATE TABLE parsers (
    id INTEGER PRIMARY KEY,
    query_id INTEGER NOT NULL,
    column TEXT NOT NULL,
    parser TEXT NOT NULL,
    FOREIGN KEY(query_id) REFERENCES queries(id)
);
