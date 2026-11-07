-- Gespreksopslag voor het geheugen van de AI-assistent.
-- user_id is een hash van het browser-token (er is geen accountsysteem); tenant-/gebruikersisolatie gebeurt in PHP.
CREATE TABLE IF NOT EXISTS conversations (
    id          TEXT NOT NULL,
    user_id     TEXT NOT NULL,
    title       TEXT NOT NULL DEFAULT '',
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL,
    PRIMARY KEY (user_id, id)
);
CREATE TABLE IF NOT EXISTS messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id         TEXT NOT NULL,
    conversation_id TEXT NOT NULL,
    role            TEXT NOT NULL CHECK (role IN ('user','assistant')),
    content         TEXT NOT NULL,
    created_at      INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_messages_user ON messages (user_id, conversation_id, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_created ON messages (created_at);
CREATE TABLE IF NOT EXISTS rate_limits (
    k  TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rate ON rate_limits (k, ts);
