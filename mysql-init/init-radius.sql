CREATE DATABASE IF NOT EXISTS radius;
USE radius;


-- Clients table
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    apellido VARCHAR(100) NOT NULL,
    cedula VARCHAR(20) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- FreeRADIUS authentication table
CREATE TABLE radcheck (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    attribute VARCHAR(64) NOT NULL DEFAULT 'Cleartext-Password',
    op VARCHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY unique_username (username)
) ENGINE=InnoDB;

-- FreeRADIUS accounting table
CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    acctstarttime DATETIME DEFAULT NULL,
    acctstoptime DATETIME DEFAULT NULL,
    acctsessiontime INT DEFAULT NULL,
    calledstationid VARCHAR(50) DEFAULT NULL,
    callingstationid VARCHAR(50) DEFAULT NULL,
    nasipaddress VARCHAR(15) DEFAULT NULL,
    acctinputoctets BIGINT DEFAULT NULL,
    acctoutputoctets BIGINT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MAC whitelist table
CREATE TABLE IF NOT EXISTS mac_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    mac_address VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create RADIUS MySQL user
CREATE USER IF NOT EXISTS 'radius'@'%' IDENTIFIED BY 'dalodbpass';
GRANT ALL PRIVILEGES ON radius.* TO 'radius'@'%';
FLUSH PRIVILEGES;
