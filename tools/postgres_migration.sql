CREATE TABLE IF NOT EXISTS users (
    id INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- Sessions moved to files on the /cache volume (application/FileSessionHandler.php).
-- Keeping them in Postgres forced a SELECT + UPSERT - and therefore a database
-- connection - on every single request, which defeated page caching entirely.
-- Existing rows are not migrated: users simply log in again.
DROP TABLE IF EXISTS php_sessions;



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

-- Add user_settings table for per-user preferences
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    login_redirect VARCHAR(20) NOT NULL DEFAULT 'default'
);

-- Add epub_progress table for EPUB CFI-based reading position
CREATE TABLE IF NOT EXISTS epub_progress (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bookid BIGINT NOT NULL,
    cfi TEXT NOT NULL,
    PRIMARY KEY (user_id, bookid)
);

-- Add djvu_progress table for DJVU page-based reading position
CREATE TABLE IF NOT EXISTS djvu_progress (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bookid BIGINT NOT NULL,
    page INT NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, bookid)
);

-- Add last_book column to user_settings for "return to last opened book" redirect option
ALTER TABLE IF EXISTS public.user_settings ADD COLUMN IF NOT EXISTS last_book INT;

-- Drop legacy seqname table (was empty; replaced by libseqname_ts for FTS)
DROP TABLE IF EXISTS public.seqname CASCADE;

-- Add author_default_tab preference to user_settings
ALTER TABLE IF EXISTS public.user_settings ADD COLUMN IF NOT EXISTS author_default_tab VARCHAR(10) NOT NULL DEFAULT 'alpha';

-- Add book_view_mode preference to user_settings
ALTER TABLE IF EXISTS public.user_settings ADD COLUMN IF NOT EXISTS book_view_mode VARCHAR(15) NOT NULL DEFAULT 'contentonly';

-- ============================================================================
-- Migration: locally added books (addbook module)
-- local_* tables are the durable source of truth for books added by the admin;
-- the lib* tables are TRUNCATEd+reloaded on every dump import, after which
-- tools/merge_local_books.php replays local_* rows back into them.
-- Ids come from sequences starting at 10000000 so they can never collide with
-- Flibusta dump ids (~900k as of 2026).
-- ============================================================================

CREATE SEQUENCE IF NOT EXISTS public.local_book_id_seq START WITH 10000000;
CREATE SEQUENCE IF NOT EXISTS public.local_author_id_seq START WITH 10000000;

CREATE TABLE IF NOT EXISTS public.local_books (
    bookid   BIGINT PRIMARY KEY,
    title    VARCHAR(254) NOT NULL,
    lang     CHAR(3) NOT NULL DEFAULT 'ru',
    year     SMALLINT NOT NULL DEFAULT 0,
    filetype CHAR(4) NOT NULL,
    filesize BIGINT NOT NULL DEFAULT 0,
    md5      BYTEA NOT NULL,
    added_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS public.local_authors (
    avtorid    BIGINT PRIMARY KEY,
    lastname   VARCHAR(99) NOT NULL DEFAULT '',
    firstname  VARCHAR(99) NOT NULL DEFAULT '',
    middlename VARCHAR(99) NOT NULL DEFAULT '',
    nickname   VARCHAR(33) NOT NULL DEFAULT '',
    added_at   TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- avtorid may reference either a dump author (libavtorname) or a local one
CREATE TABLE IF NOT EXISTS public.local_book_authors (
    bookid  BIGINT NOT NULL REFERENCES public.local_books(bookid) ON DELETE CASCADE,
    avtorid BIGINT NOT NULL,
    pos     SMALLINT NOT NULL DEFAULT 0,
    PRIMARY KEY (bookid, avtorid)
);

CREATE TABLE IF NOT EXISTS public.local_book_genres (
    bookid  BIGINT NOT NULL REFERENCES public.local_books(bookid) ON DELETE CASCADE,
    genreid BIGINT NOT NULL,
    PRIMARY KEY (bookid, genreid)
);

-- Trigram indexes for misspell-tolerant author/title search in the addbook
-- module. Survive the TRUNCATE+reload import cycle (indexes are kept on TRUNCATE).
CREATE INDEX IF NOT EXISTS idx_libavtorname_trgm ON public.libavtorname
    USING gin ((lastname || ' ' || firstname || ' ' || middlename || ' ' || nickname) gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_libbook_title_trgm ON public.libbook
    USING gin (title gin_trgm_ops);

-- ============================================================================
-- Migration: per-user hidden genres (settings module -> "Скрытые жанры")
-- Books in these genres are filtered out of the main book list.
-- Deliberately no FK to libgenrelist: that table is TRUNCATEd and reloaded on
-- every dump import, and its primary key is the pair (genreid, genrecode), so
-- genreid alone is not referenceable. Stale ids are harmless - they simply
-- match no book, and the genre may come back in a later dump.
-- ============================================================================

CREATE TABLE IF NOT EXISTS public.user_excluded_genres (
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    genreid BIGINT NOT NULL,
    PRIMARY KEY (user_id, genreid)
);
