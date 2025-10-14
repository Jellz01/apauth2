CREATE DATABASE IF NOT EXISTS radius;
USE radius;

-- Clients table
CREATE TABLE IF NOT EXISTS clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(64) NOT NULL,
    apellido VARCHAR(64) NOT NULL,
    cedula VARCHAR(10) NOT NULL UNIQUE,
    telefono VARCHAR(10),
    email VARCHAR(64),
    username VARCHAR(64) UNIQUE,
    approved TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FreeRADIUS authentication table
CREATE TABLE IF NOT EXISTS radcheck (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    attribute VARCHAR(64) NOT NULL,
    op CHAR(2) NOT NULL DEFAULT ':=',
    value VARCHAR(253) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
