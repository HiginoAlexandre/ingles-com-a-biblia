CREATE TABLE IF NOT EXISTS bible_books (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  chapter_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id), UNIQUE KEY uniq_bible_book_name (name), UNIQUE KEY uniq_bible_book_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bible_verses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  book_id INT UNSIGNED NOT NULL,
  chapter_number SMALLINT UNSIGNED NOT NULL,
  verse_number SMALLINT UNSIGNED NOT NULL,
  verse_text TEXT NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_bible_verse (book_id, chapter_number, verse_number),
  KEY idx_bible_chapter (book_id, chapter_number),
  CONSTRAINT fk_bible_verses_book FOREIGN KEY (book_id) REFERENCES bible_books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcription_table (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  original_word VARCHAR(255) NOT NULL,
  transcription VARCHAR(255) NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uniq_original_word (original_word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reading_cycles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  cycle_number INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  completed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_cycle (user_id, cycle_number),
  KEY idx_user_active_cycle (user_id, completed_at),
  CONSTRAINT fk_reading_cycles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reading_chapters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cycle_id BIGINT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  chapter_number SMALLINT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_cycle_chapter (cycle_id, book_id, chapter_number),
  KEY idx_reading_chapters_book (book_id, chapter_number),
  CONSTRAINT fk_reading_chapters_cycle FOREIGN KEY (cycle_id) REFERENCES reading_cycles(id) ON DELETE CASCADE,
  CONSTRAINT fk_reading_chapters_book FOREIGN KEY (book_id) REFERENCES bible_books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_verse_marks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  book_id INT UNSIGNED NOT NULL,
  chapter_number SMALLINT UNSIGNED NOT NULL,
  verse_number SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_verse_mark (user_id, book_id, chapter_number, verse_number),
  CONSTRAINT fk_user_verse_marks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_verse_marks_book FOREIGN KEY (book_id) REFERENCES bible_books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
