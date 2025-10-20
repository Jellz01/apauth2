-- ================================
-- Minimal FreeRADIUS Schema for MAC Authentication
-- ================================

USE radius;




-- ================================
-- 1. YOUR CLIENTS TABLE (Main table for web interface)
-- ================================
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    apellido VARCHAR(100),
    cedula VARCHAR(20),
    telefono VARCHAR(20),
    email VARCHAR(100),
    mac VARCHAR(20) UNIQUE,
    enabled TINYINT(1) DEFAULT 1
);

-- ================================
-- 2. NAS TABLE (Your Access Points/Routers)
-- Required by FreeRADIUS to know which devices can query it
-- ================================
CREATE TABLE IF NOT EXISTS nas (
    id INT(10) NOT NULL AUTO_INCREMENT,
    nasname VARCHAR(128) NOT NULL,
    shortname VARCHAR(32),
    type VARCHAR(30),
    secret VARCHAR(60) NOT NULL DEFAULT 'testing123',
    description VARCHAR(200) DEFAULT 'Access Point',
    PRIMARY KEY (id),
    KEY nasname (nasname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- 3. RADCHECK TABLE (MAC Authentication)
-- FreeRADIUS reads this for authentication
-- ================================
CREATE TABLE IF NOT EXISTS radcheck (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64),
    attribute VARCHAR(64),
    op CHAR(2),
    value VARCHAR(253)
);

-- ================================
-- OPTIONAL: Post-Auth Logging (see who tried to connect)
-- ================================
CREATE TABLE IF NOT EXISTS radpostauth (
    id INT(11) NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL DEFAULT '',
    pass VARCHAR(64) NOT NULL DEFAULT '',
    reply VARCHAR(32) NOT NULL DEFAULT '',
    authdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY username (username),
    KEY authdate (authdate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- AUTO-SYNC: clients → radcheck
-- ================================
DELIMITER $$

DROP TRIGGER IF EXISTS sync_client_insert$$
CREATE TRIGGER sync_client_insert
AFTER INSERT ON clients
FOR EACH ROW
BEGIN
    IF NEW.enabled = 1 AND NEW.mac IS NOT NULL THEN
        INSERT INTO radcheck (username, attribute, op, value)
        VALUES (NEW.mac, 'Cleartext-Password', ':=', NEW.mac)
        ON DUPLICATE KEY UPDATE value = NEW.mac;
    END IF;
END$$

DROP TRIGGER IF EXISTS sync_client_update$$
CREATE TRIGGER sync_client_update
AFTER UPDATE ON clients
FOR EACH ROW
BEGIN
    IF NEW.enabled = 1 AND NEW.mac IS NOT NULL THEN
        INSERT INTO radcheck (username, attribute, op, value)
        VALUES (NEW.mac, 'Cleartext-Password', ':=', NEW.mac)
        ON DUPLICATE KEY UPDATE value = NEW.mac;
    ELSEIF NEW.enabled = 0 AND OLD.mac IS NOT NULL THEN
        DELETE FROM radcheck WHERE username = OLD.mac;
    END IF;
    
    IF NEW.mac != OLD.mac AND OLD.mac IS NOT NULL THEN
        DELETE FROM radcheck WHERE username = OLD.mac;
        IF NEW.enabled = 1 AND NEW.mac IS NOT NULL THEN
            INSERT INTO radcheck (username, attribute, op, value)
            VALUES (NEW.mac, 'Cleartext-Password', ':=', NEW.mac);
        END IF;
    END IF;
END$$

DROP TRIGGER IF EXISTS sync_client_delete$$
CREATE TRIGGER sync_client_delete
AFTER DELETE ON clients
FOR EACH ROW
BEGIN
    IF OLD.mac IS NOT NULL THEN
        DELETE FROM radcheck WHERE username = OLD.mac;
    END IF;
END$$

DELIMITER ;

-- ================================
-- SAMPLE DATA
-- ================================

-- Add your Access Point(s)
-- Replace IP and secret with your actual AP
INSERT INTO nas (nasname, shortname, secret, description) VALUES
('192.168.1.1', 'AP-Main', 'testing123', 'Main Access Point'),
('127.0.0.1', 'localhost', 'testing123', 'Testing')
ON DUPLICATE KEY UPDATE nasname=nasname;

-- Add test clients
INSERT INTO clients (nombre, apellido, cedula, telefono, email, mac, enabled) VALUES
('Juan', 'Pérez', '0102030405', '0991234567', 'juan@example.com', 'aa:bb:cc:dd:ee:ff', 1),
('María', 'González', '0506070809', '0987654321', 'maria@example.com', '00:11:22:33:44:55', 0)
ON DUPLICATE KEY UPDATE nombre=nombre;

-- ================================
-- VERIFY
-- ================================
SELECT '✅ Schema created!' as status;
SELECT COUNT(*) as total_clients, SUM(enabled) as enabled_clients FROM clients;
SELECT COUNT(*) as authorized_macs FROM radcheck;
SELECT * FROM nas;