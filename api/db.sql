-- ============================================================
-- Softnexa backend schema  (import in phpMyAdmin)
-- ============================================================

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- default login: admin / softnexa123  (CHANGE IT after first login)
INSERT IGNORE INTO admins (username, pass_hash)
VALUES ('admin', '$2y$10$s0.Okz0dG2IE/Mc33.99W.JKrN38srXvJI8j6F0sXpEVKsynL2y/O');

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (k, v) VALUES
  ('trial_days', '14'),
  ('founding_on', '1'),
  ('founding_pct', '25'),
  ('founding_slots', '3'),
  ('founding_left', '3'),
  ('notify_email', 'hello@softnexa.solutions'),
  ('popups_on', '1');

CREATE TABLE IF NOT EXISTS leads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  name VARCHAR(120),
  company VARCHAR(160),
  email VARCHAR(160),
  phone VARCHAR(60),
  service VARCHAR(160),
  industry VARCHAR(80),
  problems TEXT,
  tools VARCHAR(255),
  team VARCHAR(60),
  urgency VARCHAR(60),
  modules TEXT,
  estimate VARCHAR(80),
  timeline VARCHAR(60),
  trial_days INT DEFAULT 0,
  founding VARCHAR(40),
  region VARCHAR(40),
  message TEXT,
  lead_score INT DEFAULT 0,
  status ENUM('new','contacted','qualified','proposal','won','lost') DEFAULT 'new',
  source VARCHAR(60) DEFAULT 'website',
  session_id VARCHAR(40),
  referrer VARCHAR(160),
  country VARCHAR(2),
  device VARCHAR(12),
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  INDEX (created_at), INDEX (status), INDEX (lead_score), INDEX (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lead_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lead_id INT NOT NULL,
  note TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visits (
  session_id VARCHAR(40) PRIMARY KEY,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pageviews INT DEFAULT 0,
  events INT DEFAULT 0,
  referrer VARCHAR(160),
  landing VARCHAR(160),
  device VARCHAR(12),
  country VARCHAR(2),
  region VARCHAR(10),
  ip VARCHAR(45),
  user_agent VARCHAR(255),
  converted TINYINT(1) DEFAULT 0,
  INDEX (first_seen), INDEX (converted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  session_id VARCHAR(40),
  type VARCHAR(40),          -- pageview | demo_open | demo_action | wizard_step | wizard_done | verify_run | cta_click | lead
  path VARCHAR(160),
  label VARCHAR(160),
  meta VARCHAR(255),
  INDEX (created_at), INDEX (type), INDEX (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_hits (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(30),
  ip VARCHAR(45),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (bucket, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
