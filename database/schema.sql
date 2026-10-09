-- ============================================================
-- CampusOrbit Database Schema (PostgreSQL)
-- ============================================================

-- Drop existing tables in reverse dependency order
DROP TABLE IF EXISTS event_attendees CASCADE;
DROP TABLE IF EXISTS events CASCADE;
DROP TABLE IF EXISTS venues CASCADE;
DROP TABLE IF EXISTS club_members CASCADE;
DROP TABLE IF EXISTS clubs CASCADE;
DROP TABLE IF EXISTS club_applications CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- Drop existing types
DROP TYPE IF EXISTS user_role CASCADE;
DROP TYPE IF EXISTS event_status CASCADE;
DROP TYPE IF EXISTS application_status CASCADE;

-- ============================================================
-- ENUM Types
-- ============================================================
CREATE TYPE user_role AS ENUM ('admin', 'president', 'student');
CREATE TYPE event_status AS ENUM ('pending', 'confirmed', 'rejected', 'not_confirmed');
CREATE TYPE application_status AS ENUM ('pending', 'approved', 'rejected');

-- ============================================================
-- users
-- ============================================================
CREATE TABLE users (
    id            SERIAL PRIMARY KEY,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          user_role NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role  ON users(role);

-- ============================================================
-- club_applications
-- ============================================================
CREATE TABLE club_applications (
    id                SERIAL PRIMARY KEY,
    club_name         VARCHAR(160) NOT NULL,
    mission           TEXT NOT NULL,
    category          VARCHAR(80) NOT NULL,
    president_name    VARCHAR(120) NOT NULL,
    president_email   VARCHAR(160) NOT NULL,
    president_password VARCHAR(255) NOT NULL,
    status            application_status NOT NULL DEFAULT 'pending',
    admin_notes       TEXT,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at       TIMESTAMP
);

CREATE INDEX idx_club_apps_status ON club_applications(status);

-- ============================================================
-- clubs
-- ============================================================
CREATE TABLE clubs (
    id            SERIAL PRIMARY KEY,
    name          VARCHAR(160) NOT NULL UNIQUE,
    mission       TEXT NOT NULL,
    category      VARCHAR(80) NOT NULL,
    president_id  INTEGER,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_clubs_president
        FOREIGN KEY (president_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_clubs_category ON clubs(category);
CREATE INDEX idx_clubs_president ON clubs(president_id);

-- ============================================================
-- club_members
-- ============================================================
CREATE TABLE club_members (
    id         SERIAL PRIMARY KEY,
    club_id    INTEGER NOT NULL,
    user_id    INTEGER NOT NULL,
    joined_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_club_members_club
        FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_club_members_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uq_club_members UNIQUE (club_id, user_id)
);

CREATE INDEX idx_club_members_club ON club_members(club_id);
CREATE INDEX idx_club_members_user ON club_members(user_id);

-- ============================================================
-- venues
-- ============================================================
CREATE TABLE venues (
    id        SERIAL PRIMARY KEY,
    name      VARCHAR(160) NOT NULL,
    capacity  INTEGER NOT NULL CHECK (capacity > 0),
    location  VARCHAR(255) NOT NULL
);

-- ============================================================
-- events
-- ============================================================
CREATE TABLE events (
    id           SERIAL PRIMARY KEY,
    club_id      INTEGER NOT NULL,
    venue_id     INTEGER NOT NULL,
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    event_date   DATE NOT NULL,
    start_time   TIME NOT NULL,
    end_time     TIME NOT NULL,
    status       event_status NOT NULL DEFAULT 'pending',
    is_featured  BOOLEAN NOT NULL DEFAULT FALSE,
    created_by   INTEGER NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
    CONSTRAINT fk_events_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_event_time CHECK (end_time > start_time)
);

CREATE INDEX idx_events_club    ON events(club_id);
CREATE INDEX idx_events_venue   ON events(venue_id);
CREATE INDEX idx_events_date    ON events(event_date);
CREATE INDEX idx_events_status  ON events(status);
CREATE INDEX idx_events_featured ON events(is_featured);

-- ============================================================
-- event_attendees
-- ============================================================
CREATE TABLE event_attendees (
    id          SERIAL PRIMARY KEY,
    event_id    INTEGER NOT NULL,
    user_id     INTEGER NOT NULL,
    rsvp_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_att_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_att_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT uq_attendee UNIQUE (event_id, user_id)
);

CREATE INDEX idx_att_event ON event_attendees(event_id);
CREATE INDEX idx_att_user  ON event_attendees(user_id);
