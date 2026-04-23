CREATE TABLE IF NOT EXISTS assistant_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mode VARCHAR(20) NOT NULL DEFAULT 'staff',
    pattern_text VARCHAR(255) NOT NULL,
    answer_text TEXT NOT NULL,
    approved TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_assistant_knowledge_mode_approved (mode, approved)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assistant_chat_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mode VARCHAR(20) NOT NULL DEFAULT 'staff',
    user_id INT DEFAULT NULL,
    deidentified_message TEXT NOT NULL,
    deidentified_reply TEXT NOT NULL,
    reply_source VARCHAR(40) NOT NULL DEFAULT 'workflow',
    knowledge_id INT DEFAULT NULL,
    feedback SMALLINT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_assistant_chat_mode_created (mode, created_at),
    INDEX idx_assistant_chat_feedback (feedback)
) ENGINE=InnoDB;
