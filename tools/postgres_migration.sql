CREATE TABLE IF NOT EXISTS users (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


CREATE TABLE IF NOT EXISTS php_sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,                 
    data          BYTEA NOT NULL,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    username VARCHAR(50),
    ip_address INET,
    user_agent TEXT,
    last_accessed TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_sessions_last_accessed 
ON php_sessions (last_accessed);



CREATE TABLE IF NOT EXISTS login_attempts (
    ip_address INET NOT NULL,
    username   VARCHAR(50),
    user_agent TEXT,
    outcome INT NOT NULL,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_attempts_id ON login_attempts(ip_address, attempt_time);
CREATE INDEX IF NOT EXISTS idx_recent_attempts ON public.login_attempts(username,attempt_time);


CREATE TABLE IF NOT EXISTS user_tokens (
    id SERIAL PRIMARY KEY,
    selector CHAR(12) UNIQUE NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    expires_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_token_selector ON user_tokens(selector);

