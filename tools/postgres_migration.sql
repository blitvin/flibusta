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

-- ============================================================================
-- Migration: Refactor favorites system for legacy/new split
-- ============================================================================

-- Step 1: Rename user_uuid column to list_uuid in fav table (legacy list link)
ALTER TABLE IF EXISTS public.fav RENAME COLUMN user_uuid TO list_uuid;

-- Step 2: Add account-bound favorites owner column (new favorites)
ALTER TABLE IF EXISTS public.fav ADD COLUMN IF NOT EXISTS user_id INT;

-- Step 3: Update the unique index on fav table to use new column name
DROP INDEX IF EXISTS public.i_fav_uniq;
CREATE UNIQUE INDEX i_fav_uniq ON public.fav USING btree (list_uuid, bookid, avtorid, seqid);

-- Step 4: Rename user_uuid column to list_uuid in fav_users table
ALTER TABLE IF EXISTS public.fav_users RENAME COLUMN user_uuid TO list_uuid;

-- Step 5: Rename fav_users table to fav_lists
ALTER TABLE IF EXISTS public.fav_users RENAME TO fav_lists;

-- Step 6: Rename the primary key constraint
ALTER TABLE IF EXISTS public.fav_lists RENAME CONSTRAINT fav_users_pkey TO fav_lists_pkey;

-- Step 7: Ensure FK from new favorites to users
ALTER TABLE IF EXISTS public.fav DROP CONSTRAINT IF EXISTS fav_user_id_fkey;
ALTER TABLE IF EXISTS public.fav
    ADD CONSTRAINT fav_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;

-- Step 8: Convert reading progress from anonymous user_uuid to account user_id.
-- Legacy progress rows cannot be mapped automatically, so they are dropped.
ALTER TABLE IF EXISTS public.progress DROP CONSTRAINT IF EXISTS progress_pkey;
TRUNCATE TABLE public.progress;
ALTER TABLE IF EXISTS public.progress ADD COLUMN IF NOT EXISTS user_id INT;
ALTER TABLE IF EXISTS public.progress DROP COLUMN IF EXISTS user_uuid;
ALTER TABLE IF EXISTS public.progress ALTER COLUMN user_id SET NOT NULL;
ALTER TABLE IF EXISTS public.progress
    ADD CONSTRAINT progress_pkey PRIMARY KEY (user_id, bookid);
ALTER TABLE IF EXISTS public.progress DROP CONSTRAINT IF EXISTS progress_user_id_fkey;
ALTER TABLE IF EXISTS public.progress
    ADD CONSTRAINT progress_user_id_fkey FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;
