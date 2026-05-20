CREATE DATABASE IF NOT EXISTS eventhub
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE eventhub;

-- ── CATEGORIES ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(50) NOT NULL UNIQUE,
    color VARCHAR(7)  DEFAULT '#2563eb'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── EVENTS ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS events (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255) NOT NULL,
    description      TEXT,
    date             DATETIME     NOT NULL,
    location         VARCHAR(255) NOT NULL,
    capacity         INT UNSIGNED NOT NULL CHECK (capacity > 0),
    category_id      INT UNSIGNED NOT NULL,
    organizer_email  VARCHAR(255) NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── REGISTRATIONS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    full_name     VARCHAR(255) NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reg_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT uq_reg_event_email
        UNIQUE (event_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── CAPACITY ALERTS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS capacity_alerts (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL UNIQUE,
    sent_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alert_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── MAIL LOGS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS mail_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          VARCHAR(50),
    recipient     VARCHAR(255),
    error_message TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── INDEX ──────────────────────────────────────────────────────
CREATE INDEX idx_events_date_category ON events(date, category_id);

-- ── DONNEES DE TEST ────────────────────────────────────────────
INSERT INTO categories (name, color) VALUES
    ('tech',     '#2563eb'),
    ('design',   '#7c3aed'),
    ('business', '#ea580c'),
    ('science',  '#16a34a');

INSERT INTO events 
    (title, description, date, location, capacity, category_id, organizer_email) 
VALUES
    ('DevFest Marrakech 2025',
     'La grande conférence tech de Marrakech. Talks, ateliers et networking.',
     '2025-09-20 09:00:00', 'ENSA Marrakech', 200, 1, 'devfest@ensa.ma'),

    ('UX Design Workshop',
     'Atelier intensif UX : prototypage, tests utilisateurs, Figma avancé.',
     '2025-07-28 14:00:00', 'École Nationale des Arts, Marrakech', 30, 2, 'ux@ena.ma'),

    ('Hackathon FinTech Maroc',
     '48h pour construire une solution fintech innovante. Prix : 50 000 MAD.',
     '2025-08-15 08:00:00', 'CBI Marrakech', 80, 1, 'fintech@cbi.ma'),

    ('Conférence IA & Médecine',
     'Comment l IA transforme le diagnostic médical au Maroc.',
     '2025-10-10 10:00:00', 'Hôpital Ibn Tofail, Marrakech', 120, 4, 'ia@ibntofail.ma'),

    ('Startup Weekend Marrakech',
     '54h pour lancer votre startup. Mentors, jury, pitchs et réseautage.',
     '2025-08-30 18:00:00', 'Université Cadi Ayyad', 60, 3, 'startup@uca.ma'),

    ('PHP & MVC Day',
     'Journée PHP 8.x, MVC natif, bonnes pratiques et sécurité.',
     '2025-11-08 09:30:00', 'ENSA Marrakech — Amphi A', 5, 1, 'php@ensa.ma');

INSERT INTO registrations (event_id, full_name, email, token) VALUES
    (1, 'Youssef Alami',   'youssef@example.ma', SHA2(CONCAT('youssef',  RAND()), 256)),
    (1, 'Fatima Zahra',    'fatima@example.ma',  SHA2(CONCAT('fatima',   RAND()), 256)),
    (2, 'Karim Benali',    'karim@example.ma',   SHA2(CONCAT('karim',    RAND()), 256)),
    (2, 'Nadia Cherkaoui', 'nadia@example.ma',   SHA2(CONCAT('nadia',    RAND()), 256)),
    (3, 'Omar Tazi',       'omar@example.ma',    SHA2(CONCAT('omar',     RAND()), 256));