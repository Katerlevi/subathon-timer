CREATE TABLE streamers (
  id TEXT PRIMARY KEY,
  twitch_user_id TEXT NOT NULL UNIQUE,
  twitch_login TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  encrypted_access_token TEXT NOT NULL,
  encrypted_refresh_token TEXT NOT NULL,
  token_expires_at INTEGER NOT NULL,
  scopes TEXT NOT NULL,
  overlay_key_hash TEXT NOT NULL UNIQUE,
  encrypted_overlay_key TEXT NOT NULL,
  setup_status TEXT NOT NULL DEFAULT 'pending',
  active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE oauth_states (
  state_hash TEXT PRIMARY KEY,
  expected_login TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE sessions (
  token_hash TEXT PRIMARY KEY,
  streamer_id TEXT NOT NULL REFERENCES streamers(id) ON DELETE CASCADE,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE timer_configs (
  streamer_id TEXT PRIMARY KEY REFERENCES streamers(id) ON DELETE CASCADE,
  start_seconds INTEGER NOT NULL DEFAULT 14400,
  max_seconds INTEGER NOT NULL DEFAULT 259200,
  tier1_seconds INTEGER NOT NULL DEFAULT 240,
  tier1_enabled INTEGER NOT NULL DEFAULT 1,
  tier2_seconds INTEGER NOT NULL DEFAULT 480,
  tier2_enabled INTEGER NOT NULL DEFAULT 1,
  tier3_seconds INTEGER NOT NULL DEFAULT 900,
  tier3_enabled INTEGER NOT NULL DEFAULT 1,
  gift_seconds INTEGER NOT NULL DEFAULT 240,
  gift_enabled INTEGER NOT NULL DEFAULT 1,
  bits_seconds INTEGER NOT NULL DEFAULT 60,
  bits_enabled INTEGER NOT NULL DEFAULT 1,
  follow_seconds INTEGER NOT NULL DEFAULT 0,
  follow_enabled INTEGER NOT NULL DEFAULT 0,
  raid_seconds INTEGER NOT NULL DEFAULT 6,
  raid_enabled INTEGER NOT NULL DEFAULT 1,
  reward_seconds INTEGER NOT NULL DEFAULT 120,
  reward_enabled INTEGER NOT NULL DEFAULT 1,
  updated_at INTEGER NOT NULL
);

CREATE TABLE timer_states (
  streamer_id TEXT PRIMARY KEY REFERENCES streamers(id) ON DELETE CASCADE,
  running INTEGER NOT NULL DEFAULT 0 CHECK (running IN (0, 1)),
  remaining_seconds INTEGER NOT NULL DEFAULT 14400,
  ends_at INTEGER NOT NULL DEFAULT 0,
  last_event TEXT,
  updated_at INTEGER NOT NULL
);

CREATE TABLE event_dedupe (
  message_id TEXT PRIMARY KEY,
  received_at INTEGER NOT NULL
);

CREATE INDEX idx_sessions_streamer_id ON sessions(streamer_id);
CREATE INDEX idx_sessions_expires_at ON sessions(expires_at);
CREATE INDEX idx_oauth_states_expires_at ON oauth_states(expires_at);
CREATE INDEX idx_event_dedupe_received_at ON event_dedupe(received_at);
PRAGMA optimize;
