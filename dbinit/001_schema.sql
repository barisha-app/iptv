CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) UNIQUE NOT NULL,
  password VARCHAR(128) NOT NULL,
  active TINYINT DEFAULT 1,
  expires_at DATETIME NULL
);

CREATE TABLE packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(64) UNIQUE NOT NULL
);

CREATE TABLE channels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  url TEXT NOT NULL,
  tvg_id VARCHAR(255),
  tvg_logo TEXT,
  grp VARCHAR(255) DEFAULT 'Live'
);

CREATE TABLE package_channels (
  package_id INT NOT NULL,
  channel_id INT NOT NULL,
  PRIMARY KEY (package_id, channel_id),
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
  FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

CREATE TABLE user_packages (
  user_id INT NOT NULL,
  package_id INT NOT NULL,
  PRIMARY KEY (user_id, package_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
);

-- Varsayılan paket
INSERT INTO packages (name) VALUES ('GENEL');
